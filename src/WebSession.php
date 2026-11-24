<?php

declare(strict_types=1);

namespace CustomerSuite;

final class WebSession
{
    private const MAX_IDLE = 3600;
    private const MAX_AGE = 43200;

    public function __construct(private readonly Database $db)
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('crm_session');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/',
            'secure' => getenv('CRM_SECURE_COOKIES') === '1' || ($_SERVER['HTTPS'] ?? '') === 'on',
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        session_start();
    }

    public function csrf(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public function verify(mixed $candidate): void
    {
        if (!is_string($candidate) || !hash_equals($this->csrf(), $candidate)) {
            throw new BusinessError('安全令牌已过期，请重新打开表单。', 403);
        }
    }

    public function login(mixed $email, mixed $password, string $ip): void
    {
        if (!is_string($email) || !is_string($password) || strlen($email) > 254 || strlen($password) > 72) {
            throw new BusinessError('邮箱或密码不正确。', 401);
        }
        $this->throttle($ip, strtolower(trim($email)));
        $identity = (new Identity($this->db))->authenticate($email, $password);
        session_regenerate_id(true);
        $_SESSION = ['id' => $identity['id'], 'auth_version' => $identity['auth_version'], 'started' => time(), 'seen' => time()];
    }

    public function actor(): ?Access
    {
        $id = $_SESSION['id'] ?? null;
        if (!is_int($id)) {
            return null;
        }
        try {
            $actor = new Access($this->db, $id);
            if ((int) $actor->user()['auth_version'] === $_SESSION['auth_version']
                && time() - $_SESSION['seen'] <= self::MAX_IDLE && time() - $_SESSION['started'] <= self::MAX_AGE
            ) {
                $_SESSION['seen'] = time();
                return $actor;
            }
        } catch (BusinessError $error) {
            if ($error->status !== 401) {
                throw $error;
            }
        }
        $this->logout();
        return null;
    }

    public function logout(): void
    {
        session_regenerate_id(true);
        $_SESSION = [];
    }

    private function throttle(string $ip, string $email): void
    {
        $bucketLimits = [
            hash('sha256', 'ip:' . $ip) => 120,
            hash('sha256', 'login:' . $ip . '|' . $email) => 8,
        ];
        ksort($bucketLimits);
        $allowed = $this->db->transaction(function () use ($bucketLimits): bool {
            $allowed = true;
            foreach ($bucketLimits as $digest => $limit) {
                $this->db->run(
                    'INSERT INTO login_throttle (bucket_digest,window_start,attempts) VALUES (UNHEX(?),UTC_TIMESTAMP(),1)
                     ON DUPLICATE KEY UPDATE
                        attempts = IF(window_start <= UTC_TIMESTAMP() - INTERVAL 15 MINUTE,1,LEAST(attempts + 1,1000)),
                        window_start = IF(window_start <= UTC_TIMESTAMP() - INTERVAL 15 MINUTE,UTC_TIMESTAMP(),window_start)',
                    [$digest],
                );
                $attempts = $this->db->row('SELECT attempts FROM login_throttle WHERE bucket_digest = UNHEX(?)', [$digest]);
                $allowed = $allowed && (int) $attempts['attempts'] <= $limit;
            }
            return $allowed;
        });
        if (!$allowed) {
            throw new BusinessError('登录尝试过多，请稍后再试。', 429);
        }
    }
}
