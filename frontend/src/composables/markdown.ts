// Leichter Markdown-Renderer für KI-Ausgaben (fett, kursiv, Code, Listen, Überschriften).
// HTML wird zuerst escaped – es werden nur bekannte Tags erzeugt (kein XSS aus dem Modelltext).

function esc(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

function inline(s: string): string {
  return esc(s)
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/__([^_]+)__/g, '<strong>$1</strong>')
    .replace(/`([^`]+)`/g, '<code class="px-1 rounded bg-black/5 text-[0.92em]">$1</code>')
    .replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>')
}

export function mdHtml(src: string | null | undefined): string {
  if (!src) return ''
  const lines = src.replace(/\r\n/g, '\n').split('\n')
  let html = ''
  let list: '' | 'ul' | 'ol' = ''
  const closeList = () => { if (list) { html += `</${list}>`; list = '' } }
  for (const raw of lines) {
    const line = raw.trimEnd()
    if (!line.trim()) { closeList(); continue }
    let m: RegExpMatchArray | null
    if ((m = line.match(/^#{1,4}\s+(.*)$/))) {
      closeList(); html += `<div class="font-semibold text-ebony mt-1">${inline(m[1])}</div>`; continue
    }
    if ((m = line.match(/^\s*[-*•·]\s+(.*)$/))) {
      if (list !== 'ul') { closeList(); html += '<ul class="list-disc pl-4 space-y-0.5 my-1">'; list = 'ul' }
      html += `<li>${inline(m[1])}</li>`; continue
    }
    if ((m = line.match(/^\s*\d+[.)]\s+(.*)$/))) {
      if (list !== 'ol') { closeList(); html += '<ol class="list-decimal pl-4 space-y-0.5 my-1">'; list = 'ol' }
      html += `<li>${inline(m[1])}</li>`; continue
    }
    closeList()
    html += `<p class="my-1">${inline(line)}</p>`
  }
  closeList()
  return html
}
