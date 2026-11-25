/**
 * Datas trafegam em ISO 8601 com fuso explícito; o backend grava em UTC
 * (REQUIREMENTS §5.14). Aqui só convertemos entre o input do navegador e a API.
 */

function pad(n: number): string {
  return String(n).padStart(2, '0')
}

/** Date → "2026-11-25T08:30:00-03:00" (horário local do dispositivo, com offset). */
export function toIsoWithOffset(date: Date): string {
  const offsetMinutes = -date.getTimezoneOffset()
  const sign = offsetMinutes >= 0 ? '+' : '-'
  const abs = Math.abs(offsetMinutes)
  return (
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
    `T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}` +
    `${sign}${pad(Math.floor(abs / 60))}:${pad(abs % 60)}`
  )
}

/** Valor de <input type="datetime-local"> ("2026-11-25T08:30") → ISO com offset, ou null. */
export function localInputToIso(value: string): string | null {
  if (!value) return null
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? null : toIsoWithOffset(date)
}

/** Para exibir datas da API no fuso do estabelecimento. */
export function formatDateTime(iso: string, timeZone = 'America/Sao_Paulo'): string {
  return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short', timeZone }).format(new Date(iso))
}
