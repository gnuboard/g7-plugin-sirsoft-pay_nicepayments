/**
 * 주문 생성 API 요청/응답 인터셉터
 *
 * G7Core 템플릿 엔진의 apiCall 핸들러는 window.fetch 를 직접 사용하므로
 * window.fetch 를 래핑하는 방식으로 동작한다.
 *
 *   1. POST /api/modules/sirsoft-ecommerce/user/orders 요청을 가로챈다
 *   2. payment_method 가 'nicepay_*' 이면 'card' 로 교체해 서버에 전송
 *      (서버의 PaymentMethodEnum 에 nicepay_* 가 없어서 422 발생 방지)
 *   3. 응답에서 pg_provider === 'sirsoft-nicepayments' 이면 requestPayment 핸들러를 직접 호출
 *   4. 원래 nicepay 방식(paymentMethod)을 requestPayment 에 전달해 올바른 결제창 호출
 *   5. requires_pg_payment → false, redirect_url → 현재 URL 로 교체
 *      → 템플릿 조건 분기가 !requires_pg_payment 쪽으로 떨어져 navigate-to-self 가 됨
 */

import { requestPaymentHandler } from './handlers/requestPayment';

const ORDER_CREATE_PATH = '/user/orders';
const TARGET_PG_PROVIDER = 'sirsoft-nicepayments';
const PLUGIN_IDENTIFIER = 'sirsoft-pay_nicepayments';
const FETCH_FLAG = '__sirsoftNicepayFetchInterceptorInstalled';
const CHECKOUT_ENDPOINT_PATH = '/api/modules/sirsoft-ecommerce/checkout';
const PAYMENT_IN_PROGRESS_FLAG = '__sirsoftNicepayPaymentInProgress';
const CHECKOUT_CACHE_KEY = '__sirsoftNicepayCheckoutCache';

const logger = {
    info: (...args: unknown[]) => console.info(`[${PLUGIN_IDENTIFIER}]`, ...args),
    warn: (...args: unknown[]) => console.warn(`[${PLUGIN_IDENTIFIER}]`, ...args),
    error: (...args: unknown[]) => console.error(`[${PLUGIN_IDENTIFIER}]`, ...args),
};

function buildNoOpRedirectUrl(): string {
    return window.location.pathname + window.location.search + window.location.hash;
}

function isTargetOrderEndpoint(url: string, method: string): boolean {
    if (method.toUpperCase() !== 'POST') return false;
    const path = (url ?? '').split('?')[0].split('#')[0];
    return path === ORDER_CREATE_PATH || path.endsWith(ORDER_CREATE_PATH);
}

function isCheckoutEndpoint(url: string, method: string): boolean {
    if (method.toUpperCase() !== 'GET') return false;
    const path = (url ?? '').split('?')[0].split('#')[0];
    return path === CHECKOUT_ENDPOINT_PATH || path.endsWith(CHECKOUT_ENDPOINT_PATH);
}

function extractPaymentMethodFromBody(body: unknown): string | undefined {
    if (!body) return undefined;
    let obj: Record<string, unknown> | null = null;
    if (typeof body === 'string') {
        try { obj = JSON.parse(body) as Record<string, unknown>; } catch { return undefined; }
    } else if (typeof body === 'object') {
        obj = body as Record<string, unknown>;
    }
    if (!obj) return undefined;
    const v = obj['payment_method'];
    return typeof v === 'string' && v.length > 0 ? v : undefined;
}

function replacePaymentMethodInBody(body: string, newMethod: string): string {
    try {
        const parsed = JSON.parse(body) as Record<string, unknown>;
        if ('payment_method' in parsed) {
            return JSON.stringify({ ...parsed, payment_method: newMethod });
        }
    } catch { /* fall through */ }
    return body;
}

export function installOrderResponseInterceptor(): void {
    const w = window as unknown as Record<string, unknown>;
    if (w[FETCH_FLAG]) return;
    w[FETCH_FLAG] = true;

    // 최초 설치 플러그인이 원본 브라우저 fetch를 보존 (다른 PG 인터셉터가 쌓이기 전)
    // easy pay 요청 시 이 fetch를 사용해 다른 PG 인터셉터(NHN KCP 등)의 간섭을 차단한다
    const ORIGINAL_FETCH_KEY = '__sirsoftPgOriginalFetch';
    if (!w[ORIGINAL_FETCH_KEY]) {
        w[ORIGINAL_FETCH_KEY] = window.fetch.bind(window);
    }
    const browserFetch = w[ORIGINAL_FETCH_KEY] as typeof fetch;

    const originalFetch = window.fetch.bind(window);

    window.fetch = async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
        const url =
            typeof input === 'string'
                ? input
                : input instanceof URL
                    ? input.href
                    : (input as Request).url;
        const method = init?.method ?? (input instanceof Request ? input.method : 'GET');

        // GET checkout 캐싱 — 결제 진행 중 페이지 재초기화 시 "주문서 없음" 다이얼로그 억제
        if (isCheckoutEndpoint(url, method)) {
            if (w[PAYMENT_IN_PROGRESS_FLAG] && w[CHECKOUT_CACHE_KEY]) {
                logger.info('payment in progress — serving cached checkout response');
                return new Response(w[CHECKOUT_CACHE_KEY] as string, {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                });
            }
            const checkoutResp = await originalFetch(input, init);
            if (checkoutResp.ok) {
                const text = await checkoutResp.text();
                w[CHECKOUT_CACHE_KEY] = text;
                return new Response(text, {
                    status: checkoutResp.status,
                    statusText: checkoutResp.statusText,
                    headers: checkoutResp.headers,
                });
            }
            return checkoutResp;
        }

        if (!isTargetOrderEndpoint(url, method)) {
            return originalFetch(input, init);
        }

        // 요청 body에서 nicepay_* 감지 → card 로 교체 후 원본 방식 보존
        let originalPaymentMethod: string | undefined;
        let modifiedInit = init;

        if (init?.body && typeof init.body === 'string') {
            const pm = extractPaymentMethodFromBody(init.body);
            if (typeof pm === 'string' && pm.startsWith('nicepay_')) {
                originalPaymentMethod = pm;
                modifiedInit = { ...init, body: replacePaymentMethodInBody(init.body, 'card') };
                logger.info(`easy pay detected: replacing payment_method '${pm}' → 'card'`);
            }
        }

        // easy pay일 때는 browserFetch(원본)를 사용해 NHN KCP 등 다른 PG 인터셉터를 우회한다.
        // "타 PG와 사용가능함"이 ON이고 기본 PG가 NHN KCP일 때 NHN KCP 결제창이 먼저 열리는 것을 방지.
        const fetchFn = originalPaymentMethod ? browserFetch : originalFetch;
        const response = await fetchFn(input, modifiedInit);

        // 2xx 응답만 처리 (4xx/5xx 오류 응답은 그대로 통과)
        if (!response.ok) {
            return response;
        }

        // 응답 파싱 (clone으로 원본 스트림 보호)
        // 서버 응답 구조: { success, message, data: { order, requires_pg_payment, pg_provider, pg_payment_data, redirect_url } }
        let envelope: Record<string, unknown> | null = null;
        try {
            envelope = (await response.clone().json()) as Record<string, unknown>;
        } catch {
            return response;
        }

        const responseData = (envelope?.data ?? envelope) as Record<string, unknown> | null;
        const isEasyPay = !!originalPaymentMethod;

        // 일반 결제: PG 결제가 필요 없으면 통과
        // 간편결제(nicepay_*): requires_pg_payment=false여도 계속 처리
        //   → 기본 PG가 미설정이면 백엔드가 requires_pg_payment:false를 반환하지만
        //     간편결제는 나이스페이먼츠 결제창을 열어야 하므로 통과시킴 (취약점 방어)
        if (!responseData?.requires_pg_payment && !isEasyPay) {
            return response;
        }

        // easy pay: pg_provider 무관하게 나이스페이 처리
        // 일반 결제: pg_provider가 나이스페이인 경우에만 처리 (나이스페이가 기본 PG일 때)
        const isNicepayPg = responseData.pg_provider === TARGET_PG_PROVIDER;

        if (!isEasyPay && !isNicepayPg) {
            return response;
        }

        // pg_payment_data: 백엔드 응답에 포함되거나,
        // 간편결제 + 기본 PG 미설정 시 order 데이터에서 직접 구성
        let pgPaymentData = responseData.pg_payment_data as Record<string, unknown> | undefined;
        if (!pgPaymentData && isEasyPay) {
            const orderData = responseData.order as Record<string, unknown> | undefined;
            if (orderData) {
                const options = orderData.options as Array<Record<string, unknown>> | undefined;
                const firstName = (options?.[0]?.product_name as string | undefined) ?? String(orderData.order_number ?? '');
                const orderName = (options?.length ?? 0) > 1
                    ? `${firstName} 외 ${(options?.length ?? 0) - 1}건`
                    : firstName;
                pgPaymentData = {
                    order_number: orderData.order_number,
                    order_name: orderName,
                    // paid_amount_local은 주문 생성 시점에 항상 0이므로 total_amount 직접 사용
                    // total_amount는 "57000.00" 형태의 문자열이므로 정수로 변환
                    amount: Math.floor(Number(orderData.total_amount ?? 0)),
                    currency: 'KRW',
                    customer_name: orderData.orderer_name ?? null,
                    customer_email: orderData.orderer_email ?? null,
                    customer_phone: String(orderData.orderer_phone ?? '').replace(/[^0-9]/g, ''),
                    customer_key: orderData.user_id ?? null,
                };
                logger.info('pg_payment_data constructed from order (기본 PG 미설정)', {
                    order_number: pgPaymentData.order_number,
                    amount: pgPaymentData.amount,
                });
            }
        }

        if (!pgPaymentData) {
            logger.warn('nicepayments order detected but pg_payment_data missing');
            return response;
        }

        const paymentMethod = originalPaymentMethod ?? 'card';

        logger.info('intercepted order create response — opening PG popup', { paymentMethod });

        void requestPaymentHandler({
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            params: { pgPaymentData: pgPaymentData as any, paymentMethod },
        });

        // 결제 진행 중 플래그 설정 — GET checkout 캐시 서빙으로 "주문서 없음" 다이얼로그 억제
        w[PAYMENT_IN_PROGRESS_FLAG] = true;

        // 결제창(팝업)이 열려 있는 동안 checkout 페이지에 머물도록
        // redirect_url을 항상 현재 URL(navigate-to-self)로 교체한다.
        // → 템플릿 fallback navigate가 무력화되어 "결제 완료" 페이지로 이동하지 않음
        // → 실제 결제 완료 후 requestPaymentHandler 콜백이 complete 페이지로 이동시킴
        // navigate-to-self로 checkout 재초기화 시 GET checkout 404 → "주문서 없음" 다이얼로그가
        //   뜰 수 있으나, PAYMENT_IN_PROGRESS_FLAG + 캐시 서빙으로 억제된다.

        // envelope.data 안에 있는 경우와 최상위에 있는 경우 모두 처리
        const modifiedData = { ...responseData, requires_pg_payment: false, redirect_url: buildNoOpRedirectUrl() };
        const modifiedBody = envelope?.data
            ? { ...envelope, data: modifiedData }
            : modifiedData;

        return new Response(JSON.stringify(modifiedBody), {
            status: response.status,
            statusText: response.statusText,
            headers: response.headers,
        });
    };

    logger.info('fetch order interceptor installed');

    // Axios 인터셉터 설치 — GET checkout 캐싱 및 결제 중 캐시 서빙
    // G7Core.api.client(Axios)가 초기화된 후 설치해야 하므로 비동기로 재시도
    tryInstallAxiosCheckoutInterceptor(w, 15);
}

const AXIOS_CHECKOUT_FLAG = '__sirsoftNicepayAxiosCheckoutInterceptorInstalled';

function tryInstallAxiosCheckoutInterceptor(w: Record<string, unknown>, retries: number): void {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const axiosClient = ((window as any)?.G7Core?.api?.client) as any;
    if (!axiosClient?.interceptors) {
        if (retries > 0) {
            setTimeout(() => tryInstallAxiosCheckoutInterceptor(w, retries - 1), 300);
        }
        return;
    }

    if (w[AXIOS_CHECKOUT_FLAG]) return;
    w[AXIOS_CHECKOUT_FLAG] = true;

    // 응답 인터셉터: GET checkout 성공 시 캐시 저장
    // Axios config.url은 baseURL을 포함하지 않으므로 (config.baseURL ?? '') + config.url 로 완성
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    axiosClient.interceptors.response.use((response: any) => {
        const method = (response?.config?.method ?? '').toLowerCase();
        const url = String((response?.config?.baseURL ?? '') + (response?.config?.url ?? ''));
        if (method === 'get' && isCheckoutEndpoint(url, 'GET') && response?.status === 200) {
            w[CHECKOUT_CACHE_KEY] = JSON.stringify(response.data);
            logger.info('checkout response cached (axios)');
        }
        return response;
    });

    // 요청 인터셉터: 결제 진행 중 GET checkout을 캐시에서 반환
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    axiosClient.interceptors.request.use((config: any) => {
        const method = (config?.method ?? '').toLowerCase();
        const url = String((config?.baseURL ?? '') + (config?.url ?? ''));
        if (method === 'get' && isCheckoutEndpoint(url, 'GET') && w[PAYMENT_IN_PROGRESS_FLAG] && w[CHECKOUT_CACHE_KEY]) {
            logger.info('payment in progress — serving cached checkout response (axios)');
            // adapter를 Promise.resolve로 교체해 실제 요청 없이 캐시 반환
            config.adapter = () => Promise.resolve({
                data: JSON.parse(w[CHECKOUT_CACHE_KEY] as string),
                status: 200,
                statusText: 'OK (cached)',
                headers: { 'content-type': 'application/json' },
                config,
                request: {},
            });
        }
        return config;
    });

    logger.info('axios checkout interceptor installed');

    // 인터셉터 설치 직후 GET checkout 즉시 캐싱 — navigate-to-self 재초기화 대비
    if (!w[CHECKOUT_CACHE_KEY]) {
        void (async () => {
            try {
                // eslint-disable-next-line @typescript-eslint/no-explicit-any
                const coreApi = ((window as any)?.G7Core?.api) as { get?: (url: string) => Promise<unknown> } | undefined;
                await coreApi?.get?.('/modules/sirsoft-ecommerce/checkout');
                // 응답은 Axios 응답 인터셉터가 자동으로 캐싱함
            } catch { /* 체크아웃 세션 없으면 실패 — 무시 */ }
        })();
    }
}

/** @deprecated Axios 인터셉터 방식 — apiCall 핸들러가 window.fetch 를 사용하므로 동작하지 않음. installOrderResponseInterceptor 를 사용할 것. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function installAxiosOrderInterceptor(_axiosClient: any): void {
    // no-op
}
