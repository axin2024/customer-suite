<?php

declare(strict_types=1);

namespace CustomerSuite;

final class Identity
{
    public function __construct(private readonly Database $db)
    {
    }

    public function bootstrap(string $name, string $email, string $password): int
    {
        $digest = $this->db->row('SELECT GET_LOCK(CONCAT(?,LEFT(SHA2(DATABASE(),256),24)),15) AS locked', ['customer-suite-admin']);
        if ((int) $digest['locked'] !== 1) {
            throw new BusinessError('管理员初始化正在运行。', 409);
        }
        try {
            if ($this->db->row('SELECT id FROM users WHERE is_admin = 1 LIMIT 1')) {
                throw new BusinessError('系统已完成管理员初始化。', 409);
            }
            return $this->createUser($name, $email, $password, true);
        } finally {
            $this->db->run('SELECT RELEASE_LOCK(CONCAT(?,LEFT(SHA2(DATABASE(),256),24)))', ['customer-suite-admin']);
        }
    }

    public function authenticate(string $email, string $password): array
    {
        $row = $this->db->row(
            'SELECT id, password_hash, auth_version FROM users WHERE email = ? AND active = 1',
            [strtolower(trim($email))],
        );
        $digest = $row['password_hash'] ?? '$2y$12$Sa05PFUEnO1/Sh6rHS1tTe/kBvxbLYd5QiCk0JffXvX.4P0gquWdq';
        if (!password_verify($password, $digest) || !$row) {
            throw new BusinessError('邮箱或密码不正确。', 401);
        }
        return ['id' => (int) $row['id'], 'auth_version' => (int) $row['auth_version']];
    }

    public function addUser(Access $actor, string $name, string $email, string $password): int
    {
        $actor->admin();
        return $this->createUser($name, $email, $password, false);
    }

    public function addCompany(Access $actor, string $code, string $name, ?int $parent = null): int
    {
        $actor->admin();
        $code = strtoupper(Input::key($code, '公司代码', 32));
        $name = Input::text($name, '公司名称', 160);
        if ($parent !== null) {
            $actor->company($parent);
        }
        return $this->db->transaction(function () use ($actor, $code, $name, $parent): int {
            if ($this->db->row('SELECT id FROM companies WHERE code = ?', [$code])) {
                throw new BusinessError('公司代码已存在。', 409);
            }
            $id = $this->db->insert('INSERT INTO companies (code,name,parent_id) VALUES (?,?,?)', [$code, $name, $parent]);
            (new Audit($this->db))->record($actor, $id, 'company', $id, 1, 'company.create');
            return $id;
        });
    }

    public function membership(Access $actor, int $companyId, int $userId, string $role, bool $granted): void
    {
        $actor->admin();
        $actor->company($companyId);
        $role = Input::choice($role, '岗位', Access::ROLES);
        $this->db->transaction(function () use ($actor, $companyId, $userId, $role, $granted): void {
            $user = $this->db->row('SELECT id, auth_version FROM users WHERE id = ? AND active = 1 FOR UPDATE', [$userId]);
            if (!$user) {
                throw new BusinessError('未找到有效的用户。', 404);
            }
            $params = [$companyId, $userId, $role];
            $present = $this->db->row('SELECT role FROM memberships WHERE company_id = ? AND user_id = ? AND role = ?', $params) !== null;
            if ($present === $granted) {
                return;
            }
            $sql = $granted
                ? 'INSERT INTO memberships (company_id,user_id,role) VALUES (?,?,?)'
                : 'DELETE FROM memberships WHERE company_id = ? AND user_id = ? AND role = ?';
            $this->db->run($sql, $params);
            $this->db->run('UPDATE users SET auth_version = auth_version + 1 WHERE id = ?', [$userId]);
            (new Audit($this->db))->record($actor, $companyId, 'user', $userId, (int) $user['auth_version'] + 1,
                $granted ? 'membership.grant' : 'membership.revoke', ['role' => $role]);
        });
    }

    private function createUser(string $name, string $email, string $password, bool $admin): int
    {
        $email = Input::email($email) ?? throw new BusinessError('须输入邮箱。');
        $name = Input::text($name, '用户姓名', 120);
        if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new BusinessError('密码长度须在 12 到 72 字节之间。');
        }
        if ($this->db->row('SELECT id FROM users WHERE email = ?', [$email])) {
            throw new BusinessError('该邮箱已注册。', 409);
        }
        return $this->db->insert(
            'INSERT INTO users (email,display_name,password_hash,is_admin) VALUES (?,?,?,?)',
            [$email, $name, password_hash($password, PASSWORD_DEFAULT), (int) $admin],
        );
    }
}
