// Rendert Suchtreffer-Markierungen sicher: zuerst HTML escapen, dann nur die
// Sentinel-Marker (⟦ ⟧ aus dem Backend/Meili) durch <mark> ersetzen.
export function hlHtml(s?: string | null): string {
  if (!s) return ''
  const esc = String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
  return esc
    .replaceAll('⟦', '<mark style="background:#f7e08c;color:#191118;border-radius:2px;padding:0 1px">')
    .replaceAll('⟧', '</mark>')
}
