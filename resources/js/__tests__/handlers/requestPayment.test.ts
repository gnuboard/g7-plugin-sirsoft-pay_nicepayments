/**
 * requestPayment 핸들러 테스트
 *
 * 나이스페이먼츠 결제창 호출 핸들러의 입력 검증 및 에러 경로 동작을 검증합니다.
 * SDK 로드/goPay 호출/모바일 redirect 등 외부 부수효과 의존 흐름은
 * tests/scenarios 매니페스트(통합 시나리오)에서 다루며, 본 단위 테스트는
 * "초기 가드 + catch 블록 정상 호출" 두 축에 집중합니다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { requestPaymentHandler } from '../../handlers/requestPayment';

const PG_PAYMENT = {
    order_number: 'ORD-001',
    order_name: 'Test Order',
    amount: 10000,
};

describe('requestPaymentHandler', () => {
    let apiGet: ReturnType<typeof vi.fn>;
    let setLocalSpy: ReturnType<typeof vi.fn>;
    let toastErrorSpy: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        apiGet = vi.fn();
        setLocalSpy = vi.fn();
        toastErrorSpy = vi.fn();
        (window as Record<string, unknown>).G7Core = {
            api: { get: apiGet },
            state: { setLocal: setLocalSpy },
            toast: { error: toastErrorSpy },
        };
    });

    afterEach(() => {
        delete (window as Record<string, unknown>).G7Core;
        vi.restoreAllMocks();
    });

    it('pgPaymentData가 없으면 console.error 후 조기 반환', async () => {
        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

        await requestPaymentHandler({ params: {} });

        expect(consoleSpy).toHaveBeenCalledWith(
            expect.stringContaining('pgPaymentData is required')
        );
        expect(apiGet).not.toHaveBeenCalled();
        expect(setLocalSpy).not.toHaveBeenCalled();
    });

    it('client config 응답에 data 가 없으면 console.error 후 조기 반환', async () => {
        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockResolvedValue({}); // data 누락

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        expect(consoleSpy).toHaveBeenCalledWith(
            expect.stringContaining('Failed to fetch client config'),
            expect.anything()
        );
        expect(setLocalSpy).not.toHaveBeenCalled();
    });

    it('client config API 자체가 throw 하면 catch 블록에서 toast.error + setLocal 복구', async () => {
        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockRejectedValue(new Error('Network error'));

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        expect(consoleSpy).toHaveBeenCalledWith(
            expect.stringContaining('requestPayment error'),
            expect.any(Error)
        );
        // catch 블록은 paymentMethod 기본값 'card' 로 setLocal 복구
        expect(setLocalSpy).toHaveBeenCalledWith({
            isSubmittingOrder: false,
            paymentMethod: 'card',
        });
        expect(toastErrorSpy).toHaveBeenCalledWith(
            expect.stringContaining('결제 중 오류가 발생')
        );
    });

    it('paramPaymentMethod 가 명시되면 catch 시 그 값으로 복구', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockRejectedValue(new Error('boom'));

        await requestPaymentHandler({
            params: {
                pgPaymentData: PG_PAYMENT,
                paymentMethod: 'nicepay_kakaopay',
            },
        });

        expect(setLocalSpy).toHaveBeenCalledWith({
            isSubmittingOrder: false,
            paymentMethod: 'nicepay_kakaopay',
        });
    });

    it('catch 블록은 결제 진행 플래그를 반드시 false 로 리셋', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        apiGet.mockRejectedValue(new Error('fail'));
        (window as unknown as Record<string, unknown>).__sirsoftNicepayPaymentInProgress = true;

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        expect(
            (window as unknown as Record<string, unknown>).__sirsoftNicepayPaymentInProgress
        ).toBe(false);
    });
});
