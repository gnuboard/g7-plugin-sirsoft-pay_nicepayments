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
        vi.restoreAllMocks();
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
