/**
 * setPaymentMethod 핸들러 테스트
 *
 * 결제수단 선택 시 _local 상태에 paymentMethod / serverPaymentMethod 를 분리 저장하는
 * 동작을 검증합니다. 간편결제(`nicepay_*`)는 G7 내부적으로 카드로 취급되어
 * `serverPaymentMethod = 'card'` 가 되어야 합니다.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { setPaymentMethodHandler } from '../../handlers/setPaymentMethod';

describe('setPaymentMethodHandler', () => {
    let setLocalSpy: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        setLocalSpy = vi.fn();
        (window as Record<string, unknown>).G7Core = {
            state: { setLocal: setLocalSpy },
        };
    });

    afterEach(() => {
        delete (window as Record<string, unknown>).G7Core;
    });

    it('paymentMethod가 없으면 setLocal을 호출하지 않는다', () => {
        setPaymentMethodHandler({ params: {} });
        expect(setLocalSpy).not.toHaveBeenCalled();
    });

    it('일반 결제수단(card)은 paymentMethod와 serverPaymentMethod에 동일 값 저장', () => {
        setPaymentMethodHandler({ params: { paymentMethod: 'card' } });

        expect(setLocalSpy).toHaveBeenCalledWith({
            paymentMethod: 'card',
            serverPaymentMethod: 'card',
        });
    });

    it('가상계좌(vbank)도 그대로 분리 저장', () => {
        setPaymentMethodHandler({ params: { paymentMethod: 'vbank' } });

        expect(setLocalSpy).toHaveBeenCalledWith({
            paymentMethod: 'vbank',
            serverPaymentMethod: 'vbank',
        });
    });

    describe('간편결제 (nicepay_*)', () => {
        it('네이버페이는 serverPaymentMethod=card 로 정규화', () => {
            setPaymentMethodHandler({ params: { paymentMethod: 'nicepay_naverpay' } });

            expect(setLocalSpy).toHaveBeenCalledWith({
                paymentMethod: 'nicepay_naverpay',
                serverPaymentMethod: 'card',
            });
        });

        it('카카오페이도 serverPaymentMethod=card', () => {
            setPaymentMethodHandler({ params: { paymentMethod: 'nicepay_kakaopay' } });

            expect(setLocalSpy).toHaveBeenCalledWith({
                paymentMethod: 'nicepay_kakaopay',
                serverPaymentMethod: 'card',
            });
        });

        it('애플페이도 serverPaymentMethod=card', () => {
            setPaymentMethodHandler({ params: { paymentMethod: 'nicepay_applepay' } });

            expect(setLocalSpy).toHaveBeenCalledWith({
                paymentMethod: 'nicepay_applepay',
                serverPaymentMethod: 'card',
            });
        });
    });

    it('G7Core가 없으면 조용히 무시 (optional chaining)', () => {
        delete (window as Record<string, unknown>).G7Core;
        // 던지지 않고 정상 종료해야 함
        expect(() => setPaymentMethodHandler({ params: { paymentMethod: 'card' } })).not.toThrow();
    });
});
