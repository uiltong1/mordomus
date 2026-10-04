<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Mordomus\Common\Support\TenantContext;
use Mordomus\Financial\Contracts\Services\BillServiceInterface;
use Mordomus\Financial\Contracts\Services\SplitRuleServiceInterface;
use Mordomus\Financial\Models\Bill;
use Mordomus\Identity\Contracts\Repositories\MembershipRepositoryInterface;
use Mordomus\Identity\Contracts\Repositories\UserRepositoryInterface;
use Mordomus\Identity\Contracts\Services\TenantProvisionerServiceInterface;
use Mordomus\Identity\Models\Role;
use Mordomus\Identity\Models\Tenant;
use Mordomus\Identity\Models\User;
use Mordomus\Maintenance\Contracts\Services\AssetServiceInterface;
use Mordomus\Maintenance\Contracts\Services\RoomServiceInterface;
use Mordomus\Maintenance\Models\Asset;
use Mordomus\Maintenance\Models\Room;
use Mordomus\Scheduling\Contracts\Services\TriggerConfigServiceInterface;
use Mordomus\Scheduling\Models\TriggerConfig;
use Throwable;

/**
 * Cenário de demonstração: duas casas com moradores, cômodos, inventário, regras
 * de manutenção, contas e divisão de custos — o estado que o painel mostra
 * quando está cheio.
 *
 * Nada é escrito direto no banco com SQL: cada linha entra pelo service que a
 * aplicação usa em produção, porque é o mesmo caminho que valida o dado, calcula
 * a próxima data e publica o evento. Um seeder que.models->insert()` produziria
 * uma casa que o painel abre e a agenda não reconhece.
 *
 * A reaplicação não duplica: tenancy, morador, cômodo, item e conta entram por
 * chave natural, e a regra de manutenção é reescrita pelo título, que é o mesmo
 * mecanismo do "salvar" do formulário.
 */
final class DemoScenario
{
    private const PASSWORD = 'senha-demo-123';

    /** Janela em que os avisos chegam; a casa acorda cedo. */
    private const HORIZON_DAYS = 45;

    /** Tabelas do cenário e o model que a guarda, para a busca por chave natural. */
    private const MODELS = [
        'rooms' => Room::class,
        'assets' => Asset::class,
        'bills' => Bill::class,
    ];

    /**
     * As duas casas do cenário.
     *
     * A segunda existe para o seletor de residência ter o que mostrar: troca de
     * casa tem que revelar outra agenda, não a mesma com outro nome.
     *
     * @var list<array<string, mixed>>
     */
    private const HOUSES = [
        [
            'name' => 'Residência Vila Madalena',
            'timezone' => 'America/Sao_Paulo',
            'preferred_hour' => '08:00',
            'owner' => ['name' => 'Ana Ribeiro', 'email' => 'ana@mordomus.test'],
            'members' => [
                ['name' => 'Bruno Lima', 'email' => 'bruno@mordomus.test'],
                ['name' => 'Carla Menezes', 'email' => 'carla@mordomus.test'],
            ],
            'rooms' => [
                ['name' => 'Cozinha', 'icon' => '🍳'],
                ['name' => 'Banheiro', 'icon' => '🚿'],
            ],
            'assets' => [
                [
                    'room' => 'Cozinha',
                    'name' => 'Geladeira',
                    'category' => 'Eletrodoméstico',
                    'brand' => 'Brastemp',
                    'model' => 'Frost Free 300',
                    'acquired_at' => '-2 years',
                    'warranty_until' => '+8 months',
                ],
                [
                    'room' => 'Cozinha',
                    'name' => 'Filtro de água',
                    'category' => 'Eletrodoméstico',
                    'brand' => 'Lorenz',
                    'model' => 'Ativo 12',
                    'warranty_until' => '+2 months',
                ],
                [
                    'room' => 'Banheiro',
                    'name' => 'Chuveiro',
                    'category' => 'Hidráulica',
                    'brand' => 'Hydra',
                    'model' => 'Pressurizado 20',
                    'warranty_until' => '+5 months',
                ],
            ],
            'rules' => [
                [
                    'asset' => 'Geladeira',
                    'title' => 'Limpeza das serpentinas',
                    'description' => 'Aspirar a serpentina e passar água quente nas aletas.',
                    'type' => TriggerConfig::TYPE_INTERVAL,
                    'interval_value' => 90,
                    'interval_unit' => 'days',
                    'advance_notice_days' => 7,
                ],
                [
                    'asset' => 'Filtro de água',
                    'title' => 'Troca do filtro',
                    'type' => TriggerConfig::TYPE_POST_COMPLETION,
                    'interval_value' => 180,
                    'interval_unit' => 'days',
                    'recalculate_base' => TriggerConfig::RECALCULATE_COMPLETION,
                    'advance_notice_days' => 3,
                ],
                [
                    'asset' => 'Chuveiro',
                    'title' => 'Limpeza do crivo',
                    'type' => TriggerConfig::TYPE_CALENDAR_MONTHLY,
                    'day_of_month' => 15,
                    'advance_notice_days' => 2,
                ],
            ],
            'bills' => [
                ['name' => 'Condomínio', 'kind' => Bill::KIND_FIXED, 'category' => 'Moradia', 'amount' => '780.00', 'due_day' => 10, 'advance_notice_days' => 3],
                ['name' => 'Energia elétrica', 'kind' => Bill::KIND_VARIABLE, 'category' => 'Utilidades', 'amount' => '210.45', 'due_day' => 15, 'advance_notice_days' => 5],
                ['name' => 'Internet fibra', 'kind' => Bill::KIND_FIXED, 'category' => 'Utilidades', 'amount' => '99.90', 'due_day' => 5, 'advance_notice_days' => 2],
                ['name' => 'Água', 'kind' => Bill::KIND_VARIABLE, 'category' => 'Utilidades', 'amount' => '88.30', 'due_day' => 20, 'advance_notice_days' => 5],
            ],
            'split' => [
                'bill' => 'Energia elétrica',
                'mode' => 'WEIGHTED',
                'weights' => ['ana' => 2, 'bruno' => 1, 'carla' => 1],
            ],
        ],
        [
            'name' => 'Apartamento Centro',
            'timezone' => 'America/Sao_Paulo',
            'preferred_hour' => '19:00',
            'owner' => ['name' => 'Diego Alves', 'email' => 'diego@mordomus.test'],
            'members' => [
                ['name' => 'Elisa Prado', 'email' => 'elisa@mordomus.test'],
            ],
            'rooms' => [
                ['name' => 'Sala', 'icon' => '🛋️'],
            ],
            'assets' => [
                [
                    'room' => 'Sala',
                    'name' => 'Ar-condicionado',
                    'category' => 'Climatização',
                    'brand' => 'LG',
                    'model' => 'Duo Split 12000 BTUs',
                    'warranty_until' => '+14 months',
                ],
                [
                    'room' => 'Sala',
                    'name' => 'Robô aspirador',
                    'category' => 'Eletrodoméstico',
                    'brand' => 'Xiaomi',
                    'model' => 'Vacuum E10',
                    'acquired_at' => '-5 months',
                ],
            ],
            'rules' => [
                [
                    'asset' => 'Ar-condicionado',
                    'title' => 'Limpeza dos filtros',
                    'description' => 'Lavar a tela do filtro e conferir o dreno.',
                    'type' => TriggerConfig::TYPE_INTERVAL,
                    'interval_value' => 30,
                    'interval_unit' => 'days',
                    'advance_notice_days' => 2,
                ],
            ],
            'bills' => [
                ['name' => 'Aluguel', 'kind' => Bill::KIND_FIXED, 'category' => 'Moradia', 'amount' => '2200.00', 'due_day' => 10, 'advance_notice_days' => 5],
                ['name' => 'Condomínio', 'kind' => Bill::KIND_FIXED, 'category' => 'Moradia', 'amount' => '430.00', 'due_day' => 5, 'advance_notice_days' => 3],
            ],
        ],
    ];

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly MembershipRepositoryInterface $memberships,
        private readonly TenantProvisionerServiceInterface $provisioner,
        private readonly RoomServiceInterface $rooms,
        private readonly AssetServiceInterface $assets,
        private readonly TriggerConfigServiceInterface $triggers,
        private readonly BillServiceInterface $bills,
        private readonly SplitRuleServiceInterface $splits,
    ) {}

    /**
     * @return array<string, int> contagem do que o cenário deixou no banco
     */
    public function run(): array
    {
        $summary = [
            'tenants' => count(self::HOUSES),
            'users' => 0,
            'rooms' => 0,
            'assets' => 0,
            'rules' => 0,
            'bills' => 0,
        ];

        foreach (self::HOUSES as $house) {
            $this->house($house, $summary);
        }

        // Uma passada só, depois das duas casas: o comando enfileira uma tarefa
        // por residência e o worker faz o material de cada uma.
        Artisan::call('scheduling:materialize', ['--days' => self::HORIZON_DAYS]);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $house
     * @param  array<string, int>  $summary
     */
    private function house(array $house, array &$summary): void
    {
        $tenant = $this->tenant($house);
        $owner = $this->resident($house['owner'], $summary);
        $residents = [$this->handleOf($house['owner']['email']) => $owner];

        foreach ($house['members'] as $member) {
            $residents[$this->handleOf($member['email'])] = $this->resident($member, $summary);
        }

        TenantContext::runWith(
            $tenant->id,
            function () use ($house, $tenant, $owner, $residents, &$summary): void {
                foreach ($house['members'] as $member) {
                    $this->memberships->activateForInvitation(
                        $residents[$this->handleOf($member['email'])],
                        $tenant->id,
                        Role::systemByKey(Role::MEMBER)->id,
                    );
                }

                $rooms = $this->rooms($house['rooms'], $tenant, $owner, $summary);
                $assets = $this->assets($house['assets'], $tenant, $owner, $rooms, $summary);
                $bills = $this->bills($house['bills'], $tenant, $owner, $summary);

                $this->rules($house['rules'], $tenant, $assets, $summary);

                if (isset($house['split'])) {
                    $this->split($house['split'], $tenant, $owner, $bills, $residents);
                }
            },
        );
    }

    /**
     * @param  array<string, mixed>  $house
     */
    private function tenant(array $house): Tenant
    {
        $existing = Tenant::query()
            ->withoutGlobalScopes()
            ->where('name', $house['name'])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $owner = $this->users->create([
            'name' => $house['owner']['name'],
            'email' => $house['owner']['email'],
            'password_hash' => self::PASSWORD,
            'locale' => 'pt_BR',
        ]);

        return $this->provisioner->create($owner, [
            'name' => $house['name'],
            'timezone' => $house['timezone'],
            'preferred_hour' => $house['preferred_hour'],
        ])['tenant'];
    }

    /**
     * @param  array{name: string, email: string}  $person
     * @param  array<string, int>  $summary
     */
    private function resident(array $person, array &$summary): User
    {
        $user = $this->users->findByEmail($person['email']);

        if ($user === null) {
            $user = $this->users->create([
                'name' => $person['name'],
                'email' => $person['email'],
                'password_hash' => self::PASSWORD,
                'locale' => 'pt_BR',
            ]);
        }

        $summary['users']++;

        return $user;
    }

    /**
     * @param  list<array<string, string>>  $definitions
     * @param  array<string, int>  $summary
     * @return array<string, string> nome do cômodo => id
     */
    private function rooms(array $definitions, Tenant $tenant, User $owner, array &$summary): array
    {
        $ids = [];

        foreach ($definitions as $room) {
            $existing = $this->findByName('rooms', $room['name']);

            $ids[$room['name']] = $existing ?? $this->rooms->store($this->as($owner, $tenant, $room))['data']['id'];
            $summary['rooms']++;
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $definitions
     * @param  array<string, string>  $rooms
     * @param  array<string, int>  $summary
     * @return array<string, string> nome do item => id
     */
    private function assets(array $definitions, Tenant $tenant, User $owner, array $rooms, array &$summary): array
    {
        $ids = [];

        foreach ($definitions as $asset) {
            $existing = $this->findByName('assets', $asset['name']);

            if ($existing !== null) {
                $ids[$asset['name']] = $existing;
                $summary['assets']++;

                continue;
            }

            $created = $this->assets->store($this->as($owner, $tenant, [
                'name' => $asset['name'],
                'room_id' => $rooms[$asset['room']],
                'category' => $asset['category'],
                'brand' => $asset['brand'],
                'model' => $asset['model'],
                'acquired_at' => $this->relativeDate($asset['acquired_at'] ?? null),
                'warranty_until' => $this->relativeDate($asset['warranty_until'] ?? null),
            ]));

            $ids[$asset['name']] = $created['data']['id'];
            $summary['assets']++;
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $definitions
     * @param  array<string, string>  $assets
     * @param  array<string, int>  $summary
     */
    private function rules(array $definitions, Tenant $tenant, array $assets, array &$summary): void
    {
        foreach ($definitions as $rule) {
            $this->triggers->storeForSubject(
                $tenant->id,
                TriggerConfig::SUBJECT_ASSET,
                $assets[$rule['asset']],
                [
                    'title' => $rule['title'],
                    'description' => $rule['description'] ?? null,
                    'type' => $rule['type'],
                    'interval_value' => $rule['interval_value'] ?? null,
                    'interval_unit' => $rule['interval_unit'] ?? null,
                    'day_of_month' => $rule['day_of_month'] ?? null,
                    'recalculate_base' => $rule['recalculate_base'] ?? null,
                    'advance_notice_days' => $rule['advance_notice_days'],
                    'is_active' => true,
                ],
            );

            $summary['rules']++;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $definitions
     * @param  array<string, int>  $summary
     * @return array<string, string> nome da conta => id
     */
    private function bills(array $definitions, Tenant $tenant, User $owner, array &$summary): array
    {
        $ids = [];

        foreach ($definitions as $bill) {
            $existing = $this->findByName('bills', $bill['name']);

            if ($existing !== null) {
                $ids[$bill['name']] = $existing;
                $summary['bills']++;

                continue;
            }

            $created = $this->bills->store($this->as($owner, $tenant, $bill));
            $ids[$bill['name']] = $created['data']['id'];
            $summary['bills']++;
        }

        return $ids;
    }

    /**
     * Divisão por peso: quem mora sozinho paga o dobro da cota de quem divide,
     * e a regra reescreve as cotas dos vencimentos que ainda estão em aberto.
     *
     * @param  array{bill: string, mode: string, weights: array<string, int|string>}  $definition
     * @param  array<string, string>  $bills
     * @param  array<string, User>  $residents
     */
    private function split(array $definition, Tenant $tenant, User $owner, array $bills, array $residents): void
    {
        $entries = [];

        foreach ($definition['weights'] as $handle => $weight) {
            if (! isset($residents[$handle])) {
                continue;
            }

            $entries[] = ['user_id' => $residents[$handle]->id, 'weight' => (string) $weight];
        }

        if (count($entries) < 2) {
            return;
        }

        $this->splits->update($this->as($owner, $tenant, [
            'bill_id' => $bills[$definition['bill']],
            'mode' => $definition['mode'],
            'entries' => $entries,
        ]));
    }

    /**
     * Requisição com o mesmo formato que o middleware entrega ao service: o
     * escopo vem do token (`tid`) e o autor vem do usuário resolvido.
     *
     * @param  array<string, mixed>  $payload
     */
    private function as(User $user, Tenant $tenant, array $payload): Request
    {
        $request = Request::create('/demo', 'POST', $payload);
        $request->attributes->set('jwt_claims', ['tid' => $tenant->id, 'sub' => $user->id]);
        $request->setUserResolver(fn (): User => $user);

        app()->instance('mordomus.tenant_id', $tenant->id);

        return $request;
    }

    /**
     * Natural key do cenário: o nome dentro da residência.
     *
     * O filtro de residência é o escopo global, então o chamador precisa estar
     * dentro de `TenantContext` — como todo o resto deste seeder.
     */
    private function findByName(string $table, string $name): ?string
    {
        $model = self::MODELS[$table];
        $id = $model::query()->where('name', $name)->value('id');

        return $id === null ? null : (string) $id;
    }

    /** `-2 years`, `+8 months` — datas de garantia não podem ser literais fixos. */
    private function relativeDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::now()->modify($value)->startOfDay()->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    /** `bruno@mordomus.test` → `bruno`: chave curta dentro do cenário. */
    private function handleOf(string $email): string
    {
        return strstr($email, '@', true) ?: $email;
    }
}
