import { useEffect, useState } from 'react'

const API_BASE = import.meta.env.VITE_API_BASE ?? 'http://localhost:8080'

const SERVICES = ['identity', 'maintenance', 'scheduling', 'financial', 'notification'] as const

type Status = 'checking' | 'ok' | 'error'

type Health = {
  service: string
  status: string
  duration_ms?: number
}

function useHealth(service: string) {
  const [status, setStatus] = useState<Status>('checking')
  const [payload, setPayload] = useState<Health | null>(null)

  useEffect(() => {
    let alive = true
    const load = async () => {
      try {
        const res = await fetch(`${API_BASE}/api/v1/${service}/health`, {
          headers: { Accept: 'application/json' },
        })
        const data = (await res.json()) as Health
        if (!alive) return
        setPayload(data)
        setStatus(res.ok && data.status === 'ok' ? 'ok' : 'error')
      } catch {
        if (alive) setStatus('error')
      }
    }
    void load()
    const timer = setInterval(load, 15000)
    return () => {
      alive = false
      clearInterval(timer)
    }
  }, [service])

  return { status, payload }
}

function ServiceCard({ service }: { service: string }) {
  const { status, payload } = useHealth(service)

  const badge =
    status === 'ok'
      ? 'bg-emerald-500/15 text-emerald-400 ring-emerald-500/30'
      : status === 'error'
        ? 'bg-rose-500/15 text-rose-400 ring-rose-500/30'
        : 'bg-slate-500/15 text-slate-400 ring-slate-500/30'

  const label = status === 'ok' ? 'ok' : status === 'error' ? 'erro' : '…'

  return (
    <article className="rounded-xl border border-slate-800 bg-slate-900/60 p-5">
      <header className="flex items-center justify-between gap-3">
        <h2 className="text-sm font-semibold tracking-wide text-slate-200">{service}</h2>
        <span
          className={`rounded-full px-2 py-0.5 text-xs font-medium ring-1 ${badge}`}
          data-testid={`status-${service}`}
        >
          {label}
        </span>
      </header>
      <dl className="mt-4 space-y-1 text-xs text-slate-400">
        <div className="flex justify-between gap-2">
          <dt>serviço</dt>
          <dd className="font-mono text-slate-300">{payload?.service ?? '—'}</dd>
        </div>
        <div className="flex justify-between gap-2">
          <dt>latência</dt>
          <dd className="font-mono text-slate-300">
            {payload?.duration_ms !== undefined ? `${payload.duration_ms} ms` : '—'}
          </dd>
        </div>
      </dl>
    </article>
  )
}

export default function App() {
  return (
    <div className="mx-auto flex min-h-full max-w-5xl flex-col gap-8 px-6 py-10">
      <header className="flex flex-col gap-1">
        <p className="text-xs font-medium uppercase tracking-[0.3em] text-sky-400">Mordomus</p>
        <h1 className="text-2xl font-semibold text-slate-100">Infraestrutura local</h1>
        <p className="text-sm text-slate-400">
          Ambiente de desenvolvimento — 5 serviços, gateway e dados isolados por residência.
        </p>
      </header>

      <main className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {SERVICES.map((service) => (
          <ServiceCard key={service} service={service} />
        ))}
      </main>

      <footer className="text-xs text-slate-500">
        API em <span className="font-mono text-slate-400">{API_BASE}</span> · atualização
        automática a cada 15 s
      </footer>
    </div>
  )
}
