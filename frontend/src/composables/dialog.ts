import { reactive } from 'vue'

export interface DialogField { key: string; label: string; value?: string; type?: 'text' | 'password' | 'textarea' }

interface State {
  open: boolean
  kind: 'confirm' | 'prompt' | 'alert'
  title: string
  message: string
  danger: boolean
  okText: string
  cancelText: string
  fields: DialogField[]
  values: Record<string, string>
}

let resolver: ((v: any) => void) | null = null

export const dialog = reactive<State>({
  open: false, kind: 'alert', title: '', message: '', danger: false,
  okText: 'OK', cancelText: 'Abbrechen', fields: [], values: {},
})

/** Ja/Nein-Bestätigung. */
export function confirmDialog(message: string, opts: { title?: string; danger?: boolean; okText?: string } = {}): Promise<boolean> {
  Object.assign(dialog, { open: true, kind: 'confirm', message, title: opts.title ?? 'Bestätigen', danger: !!opts.danger, okText: opts.okText ?? 'OK', cancelText: 'Abbrechen', fields: [], values: {} })
  return new Promise((res) => (resolver = res))
}

/** Formular-Eingabe (ein oder mehrere Felder). Liefert die Werte oder null bei Abbruch. */
export function promptDialog(fields: DialogField[], opts: { title?: string; okText?: string } = {}): Promise<Record<string, string> | null> {
  const values: Record<string, string> = {}
  fields.forEach((f) => (values[f.key] = f.value ?? ''))
  Object.assign(dialog, { open: true, kind: 'prompt', message: '', title: opts.title ?? 'Eingabe', danger: false, okText: opts.okText ?? 'Speichern', cancelText: 'Abbrechen', fields, values })
  return new Promise((res) => (resolver = res))
}

/** Hinweis mit OK. */
export function alertDialog(message: string, opts: { title?: string } = {}): Promise<void> {
  Object.assign(dialog, { open: true, kind: 'alert', message, title: opts.title ?? 'Hinweis', danger: false, okText: 'OK', cancelText: '', fields: [], values: {} })
  return new Promise((res) => (resolver = () => res()))
}

export function dialogOk(): void {
  const r = resolver
  dialog.open = false
  resolver = null
  if (!r) return
  if ('confirm' === dialog.kind) r(true)
  else if ('prompt' === dialog.kind) r({ ...dialog.values })
  else r(undefined)
}

export function dialogCancel(): void {
  const r = resolver
  dialog.open = false
  resolver = null
  if (!r) return
  if ('confirm' === dialog.kind) r(false)
  else if ('prompt' === dialog.kind) r(null)
  else r(undefined)
}
