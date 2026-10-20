export interface ErrorBody {
  code: string
  message: string
  details?: unknown
  request_id?: string | null
}

export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly details: unknown
  readonly requestId: string | null

  constructor(status: number, body: ErrorBody, cause?: unknown) {
    super(body.message, { cause })
    this.name = 'ApiError'
    this.status = status
    this.code = body.code
    this.details = body.details
    this.requestId = body.request_id ?? null
  }

  get fieldErrors(): Record<string, string[]> {
    if (
      this.code !== 'validation_failed' ||
      typeof this.details !== 'object' ||
      this.details === null
    ) {
      return {}
    }
    const out: Record<string, string[]> = {}
    for (const [field, messages] of Object.entries(this.details)) {
      out[field] = Array.isArray(messages) ? messages.map(String) : [String(messages)]
    }
    return out
  }

  get isUnauthenticated(): boolean {
    return this.status === 401
  }

  get isForbidden(): boolean {
    return this.status === 403
  }

  get isRateLimited(): boolean {
    return this.status === 429
  }

  get needsTenant(): boolean {
    return this.code === 'tenant_required'
  }

  get isNetwork(): boolean {
    return this.code === 'network_error'
  }

  get isServer(): boolean {
    return this.status >= 500
  }
}

const FALLBACK_MESSAGES: Record<number, string> = {
  401: 'Sessão expirada. Entre novamente.',
  403: 'Você não tem permissão para esta ação.',
  404: 'Recurso não encontrado.',
  409: 'Conflito com o estado atual.',
  410: 'Este recurso não está mais disponível.',
  422: 'Dados inválidos.',
  429: 'Muitas requisições — tente novamente em instantes.',
  500: 'Erro interno. Tente novamente.',
}

export async function toApiError(response: Response): Promise<ApiError> {
  const requestId = response.headers.get('X-Request-Id')
  let body: ErrorBody | null = null

  try {
    const payload: unknown = await response.json()
    if (typeof payload === 'object' && payload !== null && 'error' in payload) {
      const error = (payload as { error: unknown }).error
      if (typeof error === 'object' && error !== null) {
        const candidate = error as Partial<ErrorBody>
        body = {
          code: typeof candidate.code === 'string' ? candidate.code : 'http_error',
          message:
            typeof candidate.message === 'string'
              ? candidate.message
              : (FALLBACK_MESSAGES[response.status] ?? 'Erro inesperado.'),
          details: candidate.details,
          request_id: typeof candidate.request_id === 'string' ? candidate.request_id : requestId,
        }
      }
    }
  } catch {}

  if (body) return new ApiError(response.status, body)

  return new ApiError(response.status, {
    code: statusCode(response.status),
    message: FALLBACK_MESSAGES[response.status] ?? 'Erro inesperado.',
    request_id: requestId,
  })
}

function statusCode(status: number): string {
  return status >= 500 ? 'server_error' : 'http_error'
}

export function networkError(cause: unknown): ApiError {
  return new ApiError(
    0,
    {
      code: 'network_error',
      message: 'Não foi possível falar com o servidor. Verifique sua conexão.',
      request_id: null,
    },
    cause,
  )
}
