import { QueryClient } from '@tanstack/react-query'
import { describe, expect, it } from 'vitest'
import { resourceKeys } from './queryKeys'

const TENANT = '01m442k8kh4maxtc338bg7rc3t'

/**
 * Chave de cache é o que decide se "invalidar o inventário inteiro" chega na
 * tela do cômodo. Um filtro ausente dentro da chave quebra o prefixo e a
 * invalidação passa em silêncio: a tela só corrige quando alguém recarrega.
 */
describe('resourceKeys', () => {
  it.each([
    [
      'assets',
      resourceKeys.assets(TENANT, '01m442k9co1o54yyz8sw7jbkaq'),
      resourceKeys.assets(TENANT),
    ],
    [
      'triggerConfigs',
      resourceKeys.triggerConfigs(TENANT, 'asset'),
      resourceKeys.triggerConfigs(TENANT),
    ],
  ])('%s sem filtro é prefixo da chave filtrada', (_recurso, filtrada, semFiltro) => {
    const queryClient = new QueryClient()

    queryClient.setQueryData(filtrada, ['algo'])
    void queryClient.invalidateQueries({ queryKey: semFiltro })

    expect(queryClient.getQueryState(filtrada)?.isInvalidated).toBe(true)
  })

  it('filtros diferentes não dividem a mesma entrada', () => {
    const assets = resourceKeys.assets(TENANT, '01m442k9co1o54yyz8sw7jbkaq')
    const triggerConfigs = resourceKeys.triggerConfigs(TENANT, 'asset')
    const ocorrencias = resourceKeys.occurrences(TENANT, '2026-04-01', '2026-04-30')

    expect(new Set([assets, triggerConfigs, ocorrencias]).size).toBe(3)
  })

  it('residências diferentes não dividem a mesma entrada', () => {
    expect(resourceKeys.assets(TENANT)).not.toEqual(
      resourceKeys.assets('01m442k9co1o54yyz8sw7jbkaq'),
    )
  })
})
