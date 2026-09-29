<?php

declare(strict_types=1);

namespace Mordomus\Scheduling\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Mordomus\Http\Exceptions\TenantMismatch;
use Mordomus\Http\Pagination\OffsetPagination;
use Mordomus\Http\Tenancy\ActiveTenant;
use Mordomus\Scheduling\Contracts\Repositories\TriggerConfigRepositoryInterface;
use Mordomus\Scheduling\Contracts\Services\TenantCalendarServiceInterface;
use Mordomus\Scheduling\Contracts\Services\TriggerConfigServiceInterface;
use Mordomus\Scheduling\Contracts\Services\TriggerDateServiceInterface;
use Mordomus\Scheduling\Exceptions\TriggerConfigAlreadyExists;
use Mordomus\Scheduling\Http\Resources\TriggerConfigResource;
use Mordomus\Scheduling\Models\TriggerConfig;

/**
 * CRUD das regras de recorrência, preview e o atalho proxied.
 *
 * `next_due_at` só é escrito aqui: este módulo é o dono do tempo, e nenhuma
 * data futura é calculada fora dele.
 */
final class TriggerConfigService implements TriggerConfigServiceInterface
{
    /**
     * Campos que alteram a data calculada.
     *
     * Uma alteração fora desta lista não pode reescrever `next_due_at`: a
     * âncora padrão é `last_base_date ?? hoje`, então recalcular num dia
     * seguinte empurraria a ocorrência para frente sozinha, mesmo sem ninguém
     * ter tocado na regra.
     *
     * @var list<string>
     */
    private const DATE_FIELDS = [
        'type',
        'interval_value',
        'interval_unit',
        'day_of_month',
        'preferred_hour',
    ];

    /** Campos que descrevem o tipo; trocá-lo de tipo limpa os que sobram. */
    private const TYPE_FIELDS = [
        'interval_value',
        'interval_unit',
        'day_of_month',
        'recalculate_base',
        'custom_offsets',
    ];

    public function __construct(
        private readonly TriggerConfigRepositoryInterface $configs,
        private readonly TriggerDateServiceInterface $dates,
        private readonly TenantCalendarServiceInterface $calendar,
        private readonly TriggerConfigResource $resource,
    ) {}

    public function index(Request $request): array
    {
        $pagination = OffsetPagination::from($request);

        $configs = $this->configs->paginate(
            $this->optionalString($request->query('subject_type')),
            $this->optionalString($request->query('subject_id')),
            $pagination,
        );

        return [
            'data' => $this->resource->collection(
                $configs->getCollection(),
                $this->calendar->timezone($this->tenantId($request)),
            ),
            'meta' => $pagination->meta($configs->total(), $configs->lastPage()),
        ];
    }

    public function show(Request $request, string $triggerConfigId): array
    {
        return ['data' => $this->resource->make(
            $this->find($request, $triggerConfigId),
            $this->calendar->timezone($this->tenantId($request)),
        )];
    }

    public function store(Request $request, string $subjectType, string $subjectId): array
    {
        $tenantId = $this->tenantId($request);
        $title = $request->string('title')->toString();

        if ($this->configs->findByTitle($tenantId, $subjectType, $subjectId, $title) !== null) {
            throw TriggerConfigAlreadyExists::make([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'title' => $title,
            ]);
        }

        $config = $this->configs->create($this->attributes($request, $subjectType, $subjectId));

        return ['data' => $this->present($this->withNextDue($config, $tenantId), $tenantId)];
    }

    public function update(Request $request, string $triggerConfigId): array
    {
        $tenantId = $this->tenantId($request);
        $config = $this->find($request, $triggerConfigId);

        $attributes = $request->only([
            'title',
            'description',
            'is_active',
            'advance_notice_days',
            'preferred_hour',
            ...self::TYPE_FIELDS,
        ]);

        if ($this->typeIsChanging($request, $config)) {
            $attributes['type'] = $request->string('type')->toString();
            $attributes = $attributes + array_fill_keys(self::TYPE_FIELDS, null);
        }

        $config = $this->configs->change($config, $attributes);

        if (array_intersect(array_keys($attributes), self::DATE_FIELDS) !== []) {
            $config = $this->withNextDue($config, $tenantId);
        }

        return ['data' => $this->present($config, $tenantId)];
    }

    public function destroy(Request $request, string $triggerConfigId): array
    {
        $tenantId = $this->tenantId($request);
        $config = $this->find($request, $triggerConfigId);

        // Exclusão de verdade, não arquivamento: a regra não tem `archived_at`,
        // e a FK em cascata leva as ocorrências e a trilha junto. O caminho
        // reversível de pausar uma regra é `is_active = false`.
        $this->configs->delete($config);

        return ['data' => $this->present($config, $tenantId), 'deleted' => true];
    }

    public function storeForAsset(Request $request, string $assetId): array
    {
        $tenantId = $this->tenantId($request);
        $attributes = $this->attributes($request, TriggerConfig::SUBJECT_ASSET, $assetId);

        $existing = $this->configs->findByTitle(
            $tenantId,
            TriggerConfig::SUBJECT_ASSET,
            $assetId,
            $attributes['title'],
        );

        // Substituição completa: o atalho é um POST de formulário, então o
        // payload descreve a regra inteira.
        $config = $existing === null
            ? $this->configs->create($attributes)
            : $this->configs->change($existing, $attributes);

        return ['data' => $this->present($this->withNextDue($config, $tenantId), $tenantId)];
    }

    public function preview(Request $request): array
    {
        $tenantId = $this->tenantId($request);
        $timezone = $this->calendar->timezone($tenantId);
        $preferredHour = $this->calendar->preferredHour($this->optionalString($request->input('preferred_hour')), $tenantId);

        $config = new TriggerConfig($request->only(['type', ...self::TYPE_FIELDS]));
        $scheduledFor = $this->dates->nextDue($config, $this->previewBase($request, $timezone), $timezone);

        return ['data' => [
            'type' => $config->type,
            'scheduled_for' => $scheduledFor?->format('Y-m-d'),
            'next_due_at' => $scheduledFor === null
                ? null
                : $this->dates->dueAt($scheduledFor, $timezone, $preferredHour)->toIso8601String(),
            'preferred_hour' => $preferredHour,
            'timezone' => $timezone,
        ]];
    }

    /**
     * Calcula e grava `next_due_at` a partir da regra.
     *
     * Sem âncora explícita: quem chama já fixou a base do ciclo, e a data de
     * hoje entra sozinha quando a regra ainda nunca rodou.
     */
    private function withNextDue(TriggerConfig $config, string $tenantId): TriggerConfig
    {
        $timezone = $this->calendar->timezone($tenantId);
        $scheduledFor = $this->dates->nextDue($config, null, $timezone);

        $config->next_due_at = $scheduledFor === null
            ? null
            : $this->dates->dueAt(
                $scheduledFor,
                $timezone,
                $this->calendar->preferredHour($config->preferred_hour, $tenantId),
            );

        $config->save();

        return $config;
    }

    private function typeIsChanging(Request $request, TriggerConfig $config): bool
    {
        return $request->filled('type') && $request->string('type')->toString() !== $config->type;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Request $request, string $subjectType, string $subjectId): array
    {
        return [
            'subject_type' => $subjectType,
            'asset_id' => $subjectType === TriggerConfig::SUBJECT_ASSET ? $subjectId : null,
            'bill_id' => $subjectType === TriggerConfig::SUBJECT_BILL ? $subjectId : null,
            'title' => $request->string('title')->toString(),
            'description' => $request->input('description'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : true,
            'type' => $request->string('type')->toString(),
            'interval_value' => $request->input('interval_value'),
            'interval_unit' => $request->input('interval_unit'),
            'day_of_month' => $request->input('day_of_month'),
            'advance_notice_days' => $request->integer('advance_notice_days', 0),
            'recalculate_base' => $request->input('recalculate_base'),
            'custom_offsets' => $request->input('custom_offsets'),
            'preferred_hour' => $this->optionalString($request->input('preferred_hour')),
        ];
    }

    private function previewBase(Request $request, string $timezone): ?CarbonImmutable
    {
        $base = $this->optionalString($request->input('base'));

        if ($base === null) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $base, $timezone)->startOfDay();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TriggerConfig $config, string $tenantId): array
    {
        return $this->resource->make($config, $this->calendar->timezone($tenantId));
    }

    private function find(Request $request, string $triggerConfigId): TriggerConfig
    {
        return $this->configs->findOrFail($triggerConfigId, $this->tenantId($request));
    }

    private function tenantId(Request $request): string
    {
        // O middleware `tenant` já devolveu 403 sem `tid`; o guard serve para
        // o service nunca receber um id vazio e vazar escopo.
        return ActiveTenant::id($request) ?? throw TenantMismatch::make();
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
