<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Support;

use Illuminate\Http\Request;

/**
 * 결제 콜백 redirect URL 빌더
 *
 * Laravel 의 `redirect('/path')` 는 내부적으로 `URL::to('/path')` 로 절대 URL 을 만드는데,
 * reverse proxy 뒤 PHP-FPM 환경에서 `Request::root()` 가 `http://localhost` 로
 * 떨어지는 경우가 있다 (TrustProxies 미구성, mod_proxy_fcgi 의 Host 미전달 등).
 *
 * 이 헬퍼는 다음 우선순위로 origin 을 결정해 어떤 도메인 환경에서도 안전하게 동작하도록 한다:
 *
 *   1) X-Forwarded-Host / X-Forwarded-Proto  ─ 표준 reverse proxy 헤더
 *   2) HTTP_HOST + (HTTPS or 443 포트)        ─ 직접 연결
 *   3) Symfony Request::getSchemeAndHttpHost ─ Laravel 표준
 *   4) SERVER_NAME                            ─ 최후 fallback
 *
 * localhost / 127.0.0.1 / '' 은 마지막 후보로 강등하고, 의미 있는 호스트가
 * 하나라도 잡히면 그것을 사용한다 (운영자가 다양한 도메인으로 배포해도 동작).
 *
 * Windows / Linux 양쪽에서 동일 동작 (IIS 의 HTTP_X_ORIGINAL_HOST 도 forwarded host 와
 * 동일 의미라 case-insensitive 키 조회로 처리).
 */
final class UrlHelper
{
    /**
     * 상대 경로를 절대 URL 로 변환
     *
     * 입력이 이미 `http://` / `https://` 로 시작하면 그대로 반환. reverse proxy
     * 환경(X-Forwarded-Host) / 직접 연결(HTTP_HOST) / Symfony Request / SERVER_NAME
     * 우선순위로 origin 결정.
     *
     * @param  string  $urlOrPath  상대 경로 또는 절대 URL
     * @param  Request|null  $request  현재 요청 (없으면 글로벌 request 사용)
     * @return string 절대 URL
     */
    public static function toAbsolute(string $urlOrPath, ?Request $request = null): string
    {
        if (preg_match('#^https?://#i', $urlOrPath) === 1) {
            return $urlOrPath;
        }

        $origin = self::resolveOrigin($request);

        return rtrim($origin, '/') . '/' . ltrim($urlOrPath, '/');
    }

    /**
     * 현재 요청의 scheme + host (+ non-default port) 를 반환.
     *
     * 우선순위:
     *   1. X-Forwarded-Host / X-Forwarded-Proto (proxy)
     *   2. HTTP_HOST + HTTPS env
     *   3. Symfony Request fallback
     *   4. SERVER_NAME
     *
     * 결과가 localhost 류면 다음 후보를 시도한다.
     */
    private static function resolveOrigin(?Request $request): string
    {
        $candidates = [];

        // 1) Reverse proxy 표준 헤더
        $xfHost = self::serverVar('HTTP_X_FORWARDED_HOST') ?? self::serverVar('HTTP_X_ORIGINAL_HOST');
        if ($xfHost !== null) {
            $xfHost = self::firstFromCsv($xfHost);
            $xfProto = self::firstFromCsv(self::serverVar('HTTP_X_FORWARDED_PROTO') ?? '') ?: 'https';
            $candidates[] = self::join($xfProto, $xfHost);
        }

        // 2) 직접 HTTP_HOST + HTTPS env
        $httpHost = self::serverVar('HTTP_HOST');
        if ($httpHost !== null) {
            $proto = self::detectScheme();
            $candidates[] = self::join($proto, $httpHost);
        }

        // 3) Symfony Request (Laravel 표준)
        if ($request !== null) {
            $candidates[] = $request->getSchemeAndHttpHost();
        } else {
            try {
                $candidates[] = request()->getSchemeAndHttpHost();
            } catch (\Throwable) {
                // request() 가 실패할 환경(스케줄러/CLI)은 무시
            }
        }

        // 4) SERVER_NAME (최후)
        $serverName = self::serverVar('SERVER_NAME');
        if ($serverName !== null && $serverName !== '') {
            $candidates[] = self::join(self::detectScheme(), $serverName);
        }

        return self::pickFirstUsable($candidates);
    }

    /**
     * 후보 origin 중 localhost 류가 아닌 첫 번째를 선택.
     *
     * 모두 localhost 면 마지막 후보를 반환 (어쩔 수 없는 경우).
     */
    private static function pickFirstUsable(array $candidates): string
    {
        $candidates = array_values(array_filter(
            $candidates,
            static fn ($v) => is_string($v) && $v !== ''
        ));

        if ($candidates === []) {
            return 'http://localhost';
        }

        foreach ($candidates as $cand) {
            $host = parse_url($cand, PHP_URL_HOST) ?? '';
            $hostLower = strtolower($host);
            if ($hostLower !== 'localhost' && $hostLower !== '127.0.0.1' && $hostLower !== '::1' && $hostLower !== '') {
                return $cand;
            }
        }

        // 모두 localhost — 그래도 마지막 후보 반환 (origin 자체가 진짜 localhost 인 dev 환경 보호)
        return $candidates[count($candidates) - 1];
    }

    /**
     * HTTPS 여부 검사 (다양한 환경 대응).
     *
     * @return 'http'|'https'
     */
    private static function detectScheme(): string
    {
        // X-Forwarded-Proto (reverse proxy)
        $xfProto = self::serverVar('HTTP_X_FORWARDED_PROTO');
        if ($xfProto !== null) {
            $first = self::firstFromCsv($xfProto);
            if (strtolower($first) === 'https') {
                return 'https';
            }
        }

        // X-Forwarded-Ssl
        if (strtolower(self::serverVar('HTTP_X_FORWARDED_SSL') ?? '') === 'on') {
            return 'https';
        }

        // 직접 HTTPS env (Apache mod_ssl, IIS, 등)
        $https = self::serverVar('HTTPS');
        if ($https !== null && $https !== '' && strtolower($https) !== 'off') {
            return 'https';
        }

        // 포트 443 (역방향 검사)
        if ((int) (self::serverVar('SERVER_PORT') ?? 0) === 443) {
            return 'https';
        }

        return 'http';
    }

    /**
     * CSV 형태로 들어올 수 있는 헤더에서 첫 값 추출.
     *
     * 예) "client.example.com, proxy.example.com" → "client.example.com"
     */
    private static function firstFromCsv(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (str_contains($value, ',')) {
            return trim(explode(',', $value)[0]);
        }

        return trim($value);
    }

    /**
     * scheme + host 결합 — 이미 scheme://host 형태면 그대로.
     */
    private static function join(string $scheme, string $host): string
    {
        if (preg_match('#^https?://#i', $host) === 1) {
            return rtrim($host, '/');
        }

        return $scheme . '://' . ltrim($host, '/');
    }

    /**
     * $_SERVER 안전 조회 — 키 없으면 null.
     */
    private static function serverVar(string $key): ?string
    {
        $v = $_SERVER[$key] ?? null;

        return is_string($v) ? $v : null;
    }
}
