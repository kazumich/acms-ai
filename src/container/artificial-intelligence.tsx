import { CreateTag, ResultTag, EntryTagInitializer } from '../features/create-tag'
import { CreateTitle, ResultTitle } from '../features/create-title'
import { usePromptContext } from '../stores/use-prompt'

// Context を直接購読するため memo による最適化効果がなく、不要なラップを避ける
const TitleResultRow = () => {
  const {
    prompt: { results, status, mode }
  } = usePromptContext()

  return (
    <>
      <CreateTitle />
      {mode === 'createTitle' && status === 'loading' && <p>生成中</p>}
      {results
        .filter((result) => result.byMode === 'createTitle')
        .map((result) => (
          <div key={result.id}>
            {result.resultType === 'radio' && <ResultTitle {...result} />}
          </div>
        ))}
    </>
  )
}

const TagResultRow = () => {
  const {
    prompt: { results, status, mode }
  } = usePromptContext()

  return (
    <>
      <CreateTag />
      <EntryTagInitializer />
      {mode === 'createTag' && status === 'loading' && <p>生成中</p>}
      {results
        .filter((result) => result.byMode === 'createTag')
        .map((result) => (
          <div key={result.id}>
            {result.resultType === 'checkbox' && <ResultTag result={result} />}
          </div>
        ))}
    </>
  )
}

interface ArtificialIntelligenceProps {
  titleEnabled?: boolean
  tagEnabled?: boolean
}

export const ArtificialIntelligence = ({
  titleEnabled = true,
  tagEnabled = true,
}: ArtificialIntelligenceProps) => {
  // どちらも無効なら何も表示しない
  if (!titleEnabled && !tagEnabled) {
    return null
  }
  return (
    <table className="entryFormTable acms-admin-table-entry acms-admin-table">
      <tbody>
        {titleEnabled && (
          <tr>
            <th>タイトル候補</th>
            <td>
              <TitleResultRow />
            </td>
          </tr>
        )}
        {tagEnabled && (
          <tr>
            <th>タグ候補</th>
            <td>
              <TagResultRow />
            </td>
          </tr>
        )}
      </tbody>
    </table>
  )
}
