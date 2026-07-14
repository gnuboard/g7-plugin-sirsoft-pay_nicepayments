import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { installOrderResponseInterceptor } from '../orderResponseInterceptor';
import { requestPaymentHandler } from '../handlers/requestPayment';

vi.mock('../handlers/requestPayment', () => ({
    requestPaymentHandler: vi.fn(),
}));

const requestPaymentMock = vi.mocked(requestPaymentHandler);

function resetNicepayWindowFlags(): void {
    const w = window as unknown as Record<string, unknown>;
    delete w.__sirsoftNicepayFetchInterceptorInstalled;
    delete w.__sirsoftPgOriginalFetch;
    delete w.__sirsoftNicepayPaymentInProgress;
    delete w.__sirsoftNicepayCheckoutCache;
    delete w.__sirsoftNicepayAxiosCheckoutInterceptorInstalled;
}

/** 현재 경로를 지정한다 (jsdom 은 history API 로만 pathname 변경 가능) */
function setPathname(pathname: string): void {
    window.history.replaceState({}, '', pathname);
}

/**
 * G7Core.api 스파이를 설치하고 get 스파이를 반환한다.
 *
 * 캐시 프리페치는 tryInstallAxiosCheckoutInterceptor 안에 있고, 이 함수는
 * `G7Core.api.client`(Axios)가 준비될 때까지 재시도하다가 준비되면 프리페치한다.
 * 따라서 client(=interceptors 를 가진 객체)를 함께 제공해야 실제 경로를 탄다.
 */
function installCoreApiSpy(): ReturnType<typeof vi.fn> {
    const getSpy = vi.fn().mockResolvedValue({});
    const axiosClient = {
        interceptors: {
            request: { use: vi.fn() },
            response: { use: vi.fn() },
        },
    };
    (window as unknown as Record<string, unknown>).G7Core = {
        api: { get: getSpy, client: axiosClient },
    };

    return getSpy;
}

function buildOrderResponse(order: Record<string, unknown>): Response {
    return new Response(JSON.stringify({
        success: true,
        data: {
            order,
            requires_pg_payment: false,
            redirect_url: '/shop/orders/ORD-NICE-001/complete',
        },
    }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('installOrderResponseInterceptor', () => {
    let originalFetch: typeof window.fetch;
    let fetchSpy: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        originalFetch = window.fetch;
        fetchSpy = vi.fn();
        (window as unknown as Record<string, unknown>).fetch = fetchSpy;
        resetNicepayWindowFlags();
        requestPaymentMock.mockClear();
    });

    afterEach(() => {
        window.fetch = originalFetch;
        resetNicepayWindowFlags();
        delete (window as unknown as Record<string, unknown>).G7Core;
        setPathname('/');
        vi.restoreAllMocks();
    });

    describe('체크아웃 캐시 프리페치 라우트 가드', () => {
        // 플러그인 번들은 모든 페이지에 로드된다. 캐시 프리페치는 체크아웃 페이지의
        // navigate-to-self 재초기화를 위한 것이므로, 다른 라우트에서 발화하면
        // 비회원에게 무의미한 422(cart_key_required) 왕복만 발생시킨다.
        it.each([
            ['홈', '/'],
            ['게시판', '/board'],
            ['로그인', '/login'],
            ['상품 상세', '/shop/products/1'],
            ['장바구니', '/shop/cart'],
        ])('%s 에서는 체크아웃을 프리페치하지 않는다', async (_label, pathname) => {
            setPathname(pathname);
            const getSpy = installCoreApiSpy();

            installOrderResponseInterceptor();
            await vi.waitFor(() => expect(true).toBe(true));

            expect(getSpy).not.toHaveBeenCalled();
        });

        it('체크아웃 페이지에서는 캐시 프리페치가 동작한다', async () => {
            setPathname('/shop/checkout');
            const getSpy = installCoreApiSpy();

            installOrderResponseInterceptor();

            await vi.waitFor(() => {
                expect(getSpy).toHaveBeenCalledWith('/modules/sirsoft-ecommerce/checkout');
            });
        });

        it('이미 캐시가 있으면 체크아웃 페이지에서도 재요청하지 않는다', async () => {
            setPathname('/shop/checkout');
            (window as unknown as Record<string, unknown>).__sirsoftNicepayCheckoutCache =
                JSON.stringify({ data: {} });
            const getSpy = installCoreApiSpy();

            installOrderResponseInterceptor();
            await vi.waitFor(() => expect(true).toBe(true));

            expect(getSpy).not.toHaveBeenCalled();
        });
    });

    it('간편결제 fallback pg_payment_data 금액은 total_due_amount를 우선 사용', async () => {
        fetchSpy.mockResolvedValue(buildOrderResponse({
            order_number: 'ORD-NICE-001',
            total_amount: '57000.00',
            total_due_amount: '53000.00',
            options: [{ product_name: '테스트 상품' }],
            orderer_name: '홍길동',
            orderer_email: 'buyer@example.com',
            orderer_phone: '010-1234-5678',
            user_id: 7,
        }));

        installOrderResponseInterceptor();

        await window.fetch('/api/modules/sirsoft-ecommerce/user/orders', {
            method: 'POST',
            body: JSON.stringify({ payment_method: 'nicepay_kakaopay' }),
        });

        expect(requestPaymentMock).toHaveBeenCalledWith({
            params: {
                pgPaymentData: expect.objectContaining({
                    order_number: 'ORD-NICE-001',
                    order_name: '테스트 상품',
                    amount: 53000,
                    customer_phone: '01012345678',
                }),
                paymentMethod: 'nicepay_kakaopay',
            },
        });
    });

    it('total_due_amount가 없는 구형 응답은 total_amount로 fallback', async () => {
        fetchSpy.mockResolvedValue(buildOrderResponse({
            order_number: 'ORD-NICE-002',
            total_amount: '57000.00',
            options: [{ product_name: '테스트 상품' }],
        }));

        installOrderResponseInterceptor();

        await window.fetch('/api/modules/sirsoft-ecommerce/user/orders', {
            method: 'POST',
            body: JSON.stringify({ payment_method: 'nicepay_naverpay' }),
        });

        expect(requestPaymentMock).toHaveBeenCalledWith({
            params: {
                pgPaymentData: expect.objectContaining({
                    order_number: 'ORD-NICE-002',
                    amount: 57000,
                }),
                paymentMethod: 'nicepay_naverpay',
            },
        });
    });
});
