<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */

namespace App\Service;

use App\Common\ApiCode;
use App\Exception\BusinessException;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Redis\Redis;
use Throwable;

/**
 * 图形验证码：自绘 SVG，不依赖 GD / 字体文件。
 *
 * 什么时候要求验证码（任一满足）：
 * - 系统配置里显式开启（login_captcha_enabled）
 * - 该 IP + 用户名连续失败达到阈值（默认 3 次）
 *
 * 验证码一次性：校验时无论对错都删除，防止同一个码被反复试探。
 */
class CaptchaService
{
    /** 验证码有效期（秒） */
    private const TTL = 300;

    /** 去掉了容易混淆的 0/O/1/I/l */
    private const CHARSET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        protected Redis $redis,
        protected StdoutLoggerInterface $logger,
        protected ConfigService $configService,
        protected LoginThrottle $throttle
    ) {
    }

    /**
     * 是否需要验证码。登录页进入时先问这个接口，决定要不要展示输入框。
     */
    public function isRequired(string $ip, string $username): bool
    {
        if ($this->configService->bool('login_captcha_enabled', false)) {
            return true;
        }

        $threshold = max(1, $this->configService->int('login_captcha_threshold', 3));

        return $this->throttle->attempts($ip, $username) >= $threshold;
    }

    /**
     * 签发一张验证码。
     *
     * @return array{captcha_id: string, image: string, expires_in: int}
     */
    public function issue(): array
    {
        $code = $this->randomCode();
        $id = bin2hex(random_bytes(12));

        try {
            $this->redis->setex($this->key($id), self::TTL, strtolower($code));
        } catch (Throwable $e) {
            $this->logger->warning('验证码写入失败：' . $e->getMessage());
        }

        return [
            'captcha_id' => $id,
            'image' => 'data:image/svg+xml;base64,' . base64_encode($this->render($code)),
            'expires_in' => self::TTL,
        ];
    }

    /**
     * @throws BusinessException 未填、过期或错误
     */
    public function verify(?string $id, ?string $code): void
    {
        $id = trim((string) $id);
        $code = trim((string) $code);

        if ($id === '' || $code === '') {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '请输入图形验证码', ['captcha_required' => true]);
        }

        $key = $this->key($id);
        $expected = null;
        try {
            $expected = $this->redis->get($key);
            // 一次性：不管对错都作废，避免同一个码被暴力试探
            $this->redis->del($key);
        } catch (Throwable $e) {
            $this->logger->warning('验证码读取失败：' . $e->getMessage());
            // Redis 故障时降级放行，不能因为缓存挂了就没人能登录
            return;
        }

        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, strtolower($code))) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '验证码错误或已过期，请刷新后重试', ['captcha_required' => true]);
        }
    }

    private function key(string $id): string
    {
        return 'login:captcha:' . $id;
    }

    private function randomCode(int $length = 4): string
    {
        $max = strlen(self::CHARSET) - 1;
        $code = '';
        for ($i = 0; $i < $length; ++$i) {
            $code .= self::CHARSET[random_int(0, $max)];
        }

        return $code;
    }

    private function render(string $code): string
    {
        $width = 120;
        $height = 40;
        $chars = preg_split('//u', $code, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $parts = [];
        // 干扰线
        for ($i = 0; $i < 4; ++$i) {
            $parts[] = sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1" opacity="0.45"/>',
                random_int(0, $width),
                random_int(0, $height),
                random_int(0, $width),
                random_int(0, $height),
                $this->color(90, 150)
            );
        }
        // 噪点
        for ($i = 0; $i < 24; ++$i) {
            $parts[] = sprintf(
                '<circle cx="%d" cy="%d" r="1" fill="%s" opacity="0.5"/>',
                random_int(2, $width - 2),
                random_int(2, $height - 2),
                $this->color(100, 170)
            );
        }
        // 字符：逐个随机旋转 + 抖动，避免整齐可切
        foreach ($chars as $index => $char) {
            $x = 14 + $index * 26 + random_int(-2, 2);
            $y = 28 + random_int(-3, 3);
            $rotate = random_int(-20, 20);
            $parts[] = sprintf(
                '<text x="%d" y="%d" font-family="Menlo,Consolas,monospace" font-size="24" font-weight="700" fill="%s" transform="rotate(%d %d %d)">%s</text>',
                $x,
                $y,
                $this->color(20, 90),
                $rotate,
                $x,
                $y,
                htmlspecialchars($char, ENT_QUOTES)
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d"><rect width="100%%" height="100%%" fill="#f3f5f9"/>%s</svg>',
            $width,
            $height,
            $width,
            $height,
            implode('', $parts)
        );
    }

    private function color(int $min, int $max): string
    {
        return sprintf('#%02x%02x%02x', random_int($min, $max), random_int($min, $max), random_int($min, $max));
    }
}
