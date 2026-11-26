<?php

declare(strict_types=1);

namespace CustomerSuite;

final class Access
{
    public const ROLES = ['manager', 'marketing', 'sales', 'delivery', 'service', 'success', 'approver', 'meter', 'finance', 'subscriber'];

    public function __construct(private readonly Database $db, public readonly int $userId)
    {
    }

    public function user(): array
    {
        return $this->db->row(
            'SELECT id, email, display_name, is_admin, auth_version FROM users WHERE id = ? AND active = 1',
            [$this->userId],
        ) ?? throw new BusinessError('请重新登录。', 401);
    }

    public function admin(): void
    {
        if (!$this->user()['is_admin']) {
            throw new BusinessError('此操作需要系统管理员权限。', 403);
        }
    }

    public function companies(): array
    {
        if ($this->user()['is_admin']) {
            return $this->db->rows('SELECT id, code, name, parent_id FROM companies ORDER BY name, id');
        }
        return $this->db->rows(
            'SELECT c.id, c.code, c.name, c.parent_id FROM companies c
             WHERE EXISTS (SELECT 1 FROM memberships m WHERE m.company_id = c.id AND m.user_id = ?)
             ORDER BY c.name, c.id',
            [$this->userId],
        );
    }

    public function company(int $companyId): array
    {
        $user = $this->user();
        $company = $this->db->row(
            'SELECT c.id, c.code, c.name, c.parent_id FROM companies c WHERE c.id = ?
             AND (? = 1 OR EXISTS (SELECT 1 FROM memberships m WHERE m.company_id = c.id AND m.user_id = ?))',
            [$companyId, (int) $user['is_admin'], $this->userId],
        );
        return $company ?? throw new BusinessError('未找到当前公司。', 404);
    }

    public function has(int $companyId, string ...$roles): bool
    {
        $user = $this->user();
        if ($user['is_admin']) {
            return true;
        }
        $memberships = $this->db->rows(
            'SELECT role FROM memberships WHERE company_id = ? AND user_id = ?',
            [$companyId, $this->userId],
        );
        return array_intersect($roles, array_column($memberships, 'role')) !== [];
    }

    public function allow(int $companyId, string ...$roles): void
    {
        $this->company($companyId);
        if (!$this->has($companyId, ...$roles)) {
            throw new BusinessError('当前岗位没有这项操作权限。', 403);
        }
    }

    public function eligible(int $companyId, int $userId, string $role): void
    {
        $user = $this->db->row(
            'SELECT u.id FROM users u WHERE u.id = ? AND u.active = 1 AND
             (u.is_admin = 1 OR EXISTS (SELECT 1 FROM memberships m WHERE m.user_id = u.id AND m.company_id = ? AND m.role = ?))',
            [$userId, $companyId, $role],
        );
        if (!$user) {
            throw new BusinessError('负责人须为本公司具有对应岗位的有效用户。');
        }
    }
}
