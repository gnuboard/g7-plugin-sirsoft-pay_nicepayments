import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    resetCheckoutEasyPayInjectorForTests,
    syncRenderedCheckoutEasyPayMethods,
} from '../checkoutEasyPayInjector';

function paymentButton(label: string, description: string): string {
    return `
        <button type="button">
            <div class="flex items-center gap-3">
                <i class="fas fa-wallet" role="img"></i>
                <div>
                    <p>${label}</p>
                    <p>${description}</p>
                </div>
            </div>
        </button>
    `;
}

function plainText(element: Element | null | undefined): string {
    return (element?.textContent ?? '').replace(/\u200B/g, '').replace(/\s+/g, ' ').trim();
}

describe('checkoutEasyPayInjector', () => {
    beforeEach(() => {
        document.documentElement.lang = 'ko';
        window.history.pushState({}, '', '/shop/checkout');
        document.body.innerHTML = `
            ${paymentButton('네이\u200B버페이 (나이스페이먼츠)', '네이\u200B버페이로 결제 (나이스페이먼츠)')}
            ${paymentButton('카카\u200B오페이 (나이스페이먼츠)', '카카\u200B오페이로 결제 (나이스페이먼츠)')}
            ${paymentButton('삼성\u200B페이 (나이스페이먼츠)', '삼성\u200B페이로 결제 (나이스페이먼츠)')}
            ${paymentButton('애플페이 (나이스페이먼츠)', '애플페이로 결제 (나이스페이먼츠)')}
            ${paymentButton('PAYCO (나이스페이먼츠)', 'PAYCO로 결제 (나이스페이먼츠)')}
            ${paymentButton('11pay (나이스페이먼츠)', '11pay로 결제 (나이스페이먼츠)')}
            ${paymentButton('SSG페이 (나이스페이먼츠)', 'SSG페이로 결제 (나이스페이먼츠)')}
            ${paymentButton('L.\u200Bpay (나이스페이먼츠)', 'L.\u200Bpay로 결제 (나이스페이먼츠)')}
        `;
    });

    afterEach(() => {
        resetCheckoutEasyPayInjectorForTests();
        document.body.innerHTML = '';
    });

    it('나이스페이먼츠 간편결제를 NHN KCP처럼 아이콘과 텍스트가 있는 버튼으로 보정한다', async () => {
        await syncRenderedCheckoutEasyPayMethods();

        const buttons = Array.from(document.querySelectorAll<HTMLButtonElement>('button[data-nicepay-easy-pay-method]'));

        expect(buttons.map((button) => button.dataset.nicepayEasyPayMethod)).toEqual([
            'nicepay_naverpay',
            'nicepay_kakaopay',
            'nicepay_samsungpay',
            'nicepay_applepay',
            'nicepay_payco',
            'nicepay_skpay',
            'nicepay_ssgpay',
            'nicepay_lpay',
        ]);

        expect(buttons.map((button) => plainText(button))).toEqual([
            'N 네이버페이 (나이스페이먼츠) 네이버페이로 결제 (나이스페이먼츠)',
            'K 카카오페이 (나이스페이먼츠) 카카오페이로 결제 (나이스페이먼츠)',
            'S 삼성페이 (나이스페이먼츠) 삼성페이로 결제 (나이스페이먼츠)',
            'A 애플페이 (나이스페이먼츠) 애플페이로 결제 (나이스페이먼츠)',
            'P PAYCO (나이스페이먼츠) PAYCO로 결제 (나이스페이먼츠)',
            '11 11pay (나이스페이먼츠) 11pay로 결제 (나이스페이먼츠)',
            'SSG SSG페이 (나이스페이먼츠) SSG페이로 결제 (나이스페이먼츠)',
            'L L.pay (나이스페이먼츠) L.pay로 결제 (나이스페이먼츠)',
        ]);

        for (const button of buttons) {
            const row = button.querySelector<HTMLElement>('.flex.items-center');

            expect(button.querySelector('[data-nicepay-easy-pay-mark="true"]')).not.toBeNull();
            expect(button.style.textAlign).toBe('left');
            expect(row?.style.justifyContent).toBe('flex-start');
            expect(button.dataset.kginicisBrandPaymentMethod).toBeUndefined();
            expect(button.dataset.nhnkcpEasyPayMethod).toBeUndefined();
        }
    });
});
