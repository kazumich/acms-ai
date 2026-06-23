import { useCallback, useMemo, useState } from 'react'
import { postStreamingRequest } from '../../../api/fetcher'

export interface ChatMessage {
  id: string
  role: 'user' | 'assistant'
  content: string
  type?: 'text' | 'correction' | 'initial'
}

function parseMessageBlocks(text: string): ChatMessage[] {
  const result: ChatMessage[] = []
  const correctionRegex = /<correction>([\s\S]*?)<\/correction>/g
  let lastIndex = 0
  let match: RegExpExecArray | null

  // eslint-disable-next-line no-cond-assign
  while ((match = correctionRegex.exec(text)) !== null) {
    const before = text.slice(lastIndex, match.index).trim()
    if (before) result.push({ id: crypto.randomUUID(), role: 'assistant', type: 'text', content: before })
    const correction = match[1].trim()
    if (correction) result.push({ id: crypto.randomUUID(), role: 'assistant', type: 'correction', content: correction })
    lastIndex = match.index + match[0].length
  }

  const after = text.slice(lastIndex).trim()
  if (after) result.push({ id: crypto.randomUUID(), role: 'assistant', type: 'text', content: after })

  if (result.length === 0 && text.trim()) {
    result.push({ id: crypto.randomUUID(), role: 'assistant', type: 'text', content: text.trim() })
  }
  return result
}

interface ChatState {
  messages: ChatMessage[]
}

const chatStateStore = new Map<string, ChatState>()

/**
 * 表示用メッセージ列を、API へ送る会話履歴（role/content）へ整形する。
 * 連続する同一ロール（initial+本文、text+correction など）は結合し、
 * user/assistant が交互になるようにする（Claude/Gemini の制約に合わせるため）。
 */
function buildHistory(msgs: ChatMessage[]): { role: 'user' | 'assistant'; content: string }[] {
  const out: { role: 'user' | 'assistant'; content: string }[] = []
  for (const m of msgs) {
    const content = m.content.trim()
    if (!content) continue
    const last = out[out.length - 1]
    if (last && last.role === m.role) {
      last.content += `\n\n${content}`
    } else {
      out.push({ role: m.role, content })
    }
  }
  return out
}

interface SSERawEvent {
  type?: string
  delta?: string
  message?: string
  response?: { id?: string }
}

/**
 * ReadableStream から SSE イベントを読み取り、各コールバックに委譲する純粋関数。
 * `response.completed` で accumulatedText をリセットし、残余テキストを返す。
 */
async function processSSEStream(
  reader: ReadableStreamDefaultReader<Uint8Array>,
  onDelta: (accumulatedText: string) => void,
  onCompleted: (text: string) => void,
  onError: (message: string) => void,
): Promise<string> {
  const decoder = new TextDecoder()
  let buffer = ''
  let accumulatedText = ''

  // eslint-disable-next-line no-constant-condition
  while (true) {
    const { done, value } = await reader.read()
    if (done) break

    buffer += decoder.decode(value, { stream: true })
    const lines = buffer.split('\n')
    buffer = lines.pop() ?? ''

    for (const line of lines) {
      if (!line.startsWith('data: ')) continue
      const jsonStr = line.slice(6)
      if (jsonStr === '[DONE]') continue

      try {
        const event = JSON.parse(jsonStr) as SSERawEvent

        if (event.type === 'error') {
          onError(event.message ?? 'エラーが発生しました。')
          return accumulatedText
        }

        if (event.type === 'response.output_text.delta' && event.delta) {
          accumulatedText += event.delta
          onDelta(accumulatedText)
        }

        if (event.type === 'response.completed') {
          const completed = accumulatedText
          accumulatedText = ''
          onCompleted(completed)
        }
      } catch {
        // Skip malformed JSON
      }
    }
  }

  return accumulatedText
}

export function clearChatState(chatId: string): void {
  chatStateStore.delete(chatId)
}

export interface UseChatOptions {
  onError?: (message: string) => void
  chatId?: string
  initialContent?: string
  silent?: boolean
}

export function useChat({ onError, chatId, initialContent, silent }: UseChatOptions = {}) {
  const stored = chatId ? chatStateStore.get(chatId) : undefined
  const [messages, setMessages] = useState<ChatMessage[]>(() => {
    if (stored) return stored.messages
    return initialContent
      ? [{ id: crypto.randomUUID(), role: 'user', type: 'initial', content: initialContent }]
      : []
  })
  const [streamingContent, setStreamingContent] = useState('')
  const [isLoading, setIsLoading] = useState(false)

  const setMessagesAndSave = useCallback(
    (updater: (prev: ChatMessage[]) => ChatMessage[]) => {
      setMessages((prev) => {
        const next = updater(prev)
        if (chatId) {
          chatStateStore.set(chatId, { messages: next })
        }
        return next
      })
    },
    [chatId]
  )

  const sendMessage = useCallback(
    async (content: string) => {
      if (!content.trim() || isLoading) return

      const userMessage: ChatMessage = { id: crypto.randomUUID(), role: 'user', content: content.trim() }
      const history = buildHistory([...messages, userMessage])
      setMessagesAndSave((prev) => [...prev, userMessage])
      setIsLoading(true)
      setStreamingContent('')

      const data = {
        messages: JSON.stringify(history),
        ...(silent && { silent: '1' }),
      }

      const result = await postStreamingRequest({
        url: window.ACMS.Config.root,
        data,
        exec: 'ACMS_POST_AI_Chat',
        formToken: window.csrfToken,
      })

      if (!result.ok) {
        const msg =
          result.status === 404
            ? 'チャットAPIが見つかりません。プラグインの設定を確認してください。'
            : result.status === 500
              ? `サーバーエラー (${result.status})。APIキーやモデルの設定を確認してください。`
              : `接続に失敗しました。(${result.status})`
        onError?.(msg)
        setIsLoading(false)
        return
      }

      if (!result.response.body) {
        onError?.('レスポンスボディが空です。')
        setIsLoading(false)
        return
      }
      const reader = result.response.body.getReader()

      try {
        const remaining = await processSSEStream(
          reader,
          (accumulatedText) => setStreamingContent(accumulatedText),
          (text) => {
            if (text) {
              const blocks = parseMessageBlocks(text)
              setMessagesAndSave((msgs) => [...msgs, ...blocks])
            }
            setStreamingContent('')
          },
          (message) => onError?.(message),
        )

        // Flush remaining streaming content (if stream ended without response.completed)
        if (remaining) {
          const blocks = parseMessageBlocks(remaining)
          setMessagesAndSave((msgs) => [...msgs, ...blocks])
        }
        setStreamingContent('')
      } catch (e) {
        console.error('Stream read error:', e)
        onError?.('ストリーミング中にエラーが発生しました。')
      } finally {
        setIsLoading(false)
      }
    },
    [isLoading, messages, onError, setMessagesAndSave, silent]
  )

  const lastAssistantContent = useMemo(
    () => messages.filter((m) => m.role === 'assistant').pop()?.content,
    [messages]
  )

  return {
    messages,
    streamingContent,
    isLoading,
    sendMessage,
    lastAssistantContent,
  }
}
