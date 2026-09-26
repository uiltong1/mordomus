<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Mordomus\Identity\Models\Permission;
use Mordomus\Identity\Models\Role;

/**
 * Catálogo de capabilities + roles de sistema.
 * Idempotente: seguro para reaplicar a cada `migrate --seed`.
 */
class RbacSeeder extends Seeder
{
    /** Catálogo completo. */
    public const CAPABILITIES = [
        'tenant.manage' => 'Gerenciar a residência (nome, fuso, preferências, arquivamento)',
        'members.manage' => 'Convidar moradores e alterar roles/grants',
        'rooms.manage' => 'Gerenciar ambientes do imóvel',
        'assets.manage' => 'Gerenciar ativos do imóvel',
        'rules.edit' => 'Criar e editar regras de manutenção',
        'occurrences.complete' => 'Concluir ocorrências',
        'occurrences.skip' => 'Pular ocorrências',
        'bills.manage' => 'Gerenciar contas e vencimentos',
        'bills.pay' => 'Registrar pagamentos',
        'splits.manage' => 'Gerenciar regras de divisão de custos',
        'splits.view_own' => 'Consultar a própria cota-parte',
        'notifications.manage' => 'Gerenciar preferências de notificação',
    ];

    /** Política padrão: member recebe só estas. */
    public const MEMBER_CAPABILITIES = [
        'occurrences.complete',
        'occurrences.skip',
        'bills.pay',
        'splits.view_own',
        'notifications.manage',
    ];

    public function run(): void
    {
        $permissions = collect(self::CAPABILITIES)
            ->mapWithKeys(function (string $description, string $key) {
                return [$key => Permission::firstOrCreate(['key' => $key], ['description' => $description])];
            });

        $owner = Role::firstOrCreate(
            ['tenant_id' => null, 'key' => Role::OWNER],
            ['name' => 'Proprietário', 'is_system' => true],
        );

        $member = Role::firstOrCreate(
            ['tenant_id' => null, 'key' => Role::MEMBER],
            ['name' => 'Morador', 'is_system' => true],
        );

        // owner = todas as capabilities
        $owner->permissions()->sync(
            $permissions->mapWithKeys(fn (Permission $permission) => [$permission->id => ['granted' => true]])
        );

        // member = política padrão
        $member->permissions()->sync(
            $permissions
                ->only(self::MEMBER_CAPABILITIES)
                ->mapWithKeys(fn (Permission $permission) => [$permission->id => ['granted' => true]])
        );
    }
}
