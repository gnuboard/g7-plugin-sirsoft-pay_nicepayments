const PLUGIN_ID = 'sirsoft-pay_nicepayments';
const FLAG = '__nicepayCheckoutEasyPayInjectorInstalled';
const CHECKOUT_RE = /^\/shop\/checkout\/?$/;
const LISTENER_FLAG = '__nicepayCheckoutEasyPaySyncListenerAttached';
const SYNC_RETRY_INTERVAL_MS = 200;
const SYNC_RETRY_ATTEMPTS = 120;
const LEGACY_CONTAINER_ID = 'nicepay_checkout_payment_section';

interface EasyPayCopy {
    heading: string;
    description: string;
    title: string;
}

interface EasyPayDefinition {
    id: string;
    labels: string[];
    ko: EasyPayCopy;
    en: EasyPayCopy;
    markText: string;
    markClassName: string;
}

const EASY_PAY_DEFINITIONS: EasyPayDefinition[] = [
    {
        id: 'nicepay_naverpay',
        labels: [
            '네이버페이 (나이스페이먼츠)',
            '네이버페이로 결제 (나이스페이먼츠)',
            'Naver Pay (NicePayments)',
            'Pay with Naver Pay (NicePayments)',
        ],
        ko: {
            heading: '네이버페이 (나이스페이먼츠)',
            description: '네이버페이로 결제 (나이스페이먼츠)',
            title: '네이버페이로 결제 (나이스페이먼츠)',
        },
        en: {
            heading: 'Naver Pay (NicePayments)',
            description: 'Pay with Naver Pay (NicePayments)',
            title: 'Pay with Naver Pay (NicePayments)',
        },
        markText: 'N',
        markClassName: 'bg-green-500 text-white',
    },
    {
        id: 'nicepay_kakaopay',
        labels: [
            '카카오페이 (나이스페이먼츠)',
            '카카오페이로 결제 (나이스페이먼츠)',
            'Kakao Pay (NicePayments)',
            'Pay with Kakao Pay (NicePayments)',
        ],
        ko: {
            heading: '카카오페이 (나이스페이먼츠)',
            description: '카카오페이로 결제 (나이스페이먼츠)',
            title: '카카오페이로 결제 (나이스페이먼츠)',
        },
        en: {
            heading: 'Kakao Pay (NicePayments)',
            description: 'Pay with Kakao Pay (NicePayments)',
            title: 'Pay with Kakao Pay (NicePayments)',
        },
        markText: 'K',
        markClassName: 'bg-yellow-400 text-gray-950',
    },
    {
        id: 'nicepay_samsungpay',
        labels: [
            '삼성페이 (나이스페이먼츠)',
            '삼성페이로 결제 (나이스페이먼츠)',
            'Samsung Pay (NicePayments)',
            'Pay with Samsung Pay (NicePayments)',
        ],
        ko: {
            heading: '삼성페이 (나이스페이먼츠)',
            description: '삼성페이로 결제 (나이스페이먼츠)',
            title: '삼성페이로 결제 (나이스페이먼츠)',
        },
        en: {
            heading: 'Samsung Pay (NicePayments)',
            description: 'Pay with Samsung Pay (NicePayments)',
            title: 'Pay with Samsung Pay (NicePayments)',
        },
        markText: 'S',
        markClassName: 'bg-blue-600 text-white',
    },
    {
        id: 'nicepay_applepay',
        labels: [
            '애플페이 (나이스페이먼츠)',
            '애플페이로 결제 (나이스페이먼츠)',
            'Apple Pay (NicePayments)',
            'Pay with Apple Pay (NicePayments)',
        ],
        ko: {
            heading: '애플페이 (나이스페이먼츠)',
            description: '애플페이로 결제 (나이스페이먼츠)',
            title: '애플페이로 결제 (나이스페이먼츠)',
        },
        en: {
            heading: 'Apple Pay (NicePayments)',
            description: 'Pay with Apple Pay (NicePayments)',
            title: 'Pay with Apple Pay (NicePayments)',
        },
        markText: 'A',
        markClassName: 'bg-gray-900 text-white',
    },
    {
        id: 'nicepay_payco',
        labels: [
            'PAYCO (나이스페이먼츠)',
            'PAYCO로 결제 (나이스페이먼츠)',
            'PAYCO (NicePayments)',
            'Pay with PAYCO (NicePayments)',
        ],
        ko: {
            heading: 'PAYCO (나이스페이먼츠)',
            description: 'PAYCO로 결제 (나이스페이먼츠)',
            title: 'PAYCO로 결제 (나이스페이먼츠)',
        },
        en: {
            heading: 'PAYCO (NicePayments)',
            description: 'Pay with PAYCO (NicePayments)',
            title: 'Pay with PAYCO (NicePayments)',
        },
        markText: 'P',
        markClassName: 'bg-red-500 text-white',
    },
    {
        id: 'nicepay_skpay',
        labels: [
            '11pay (나이스페이먼츠)',
            '11pay로 결제 (나이스페이먼츠)',
            '11pay (NicePayments)',
            'Pay with 11pay (NicePayments)',
        ],
        ko: {
            heading: '11pay (나이스페이먼츠)',
            description: '11pay로 결제 (나이스페이먼츠)',
            title: '11pay로 결제 (나이스페이먼츠)',
        },
        en: {
            heading: '11pay (NicePayments)',
            description: 'Pay with 11pay (NicePayments)',
            title: 'Pay with 11pay (NicePayments)',
        },
        markText: '11',
        markClassName: 'bg-orange-500 text-white',
    },
    {
        id: 'nicepay_ssgpay',
        labels: [
            'SSG페이 (나이스페이먼츠)',
            'SSG페이로 결제 (나이스페이먼츠)',
            'SSG Pay (NicePayments)',
            'Pay with SSG Pay (NicePayments)',
        ],
        ko: {
            heading: 'SSG페이 (나이스페이먼츠)',
            description: 'SSG페이로 결제 (나이스페이먼츠)',
            title: 'SSG페이로 결제 (나이스페이먼츠)',
        },
        en: {
            heading: 'SSG Pay (NicePayments)',
            description: 'Pay with SSG Pay (NicePayments)',
            title: 'Pay with SSG Pay (NicePayments)',
        },
        markText: 'SSG',
        markClassName: 'bg-red-700 text-white',
    },
    {
        id: 'nicepay_lpay',
        labels: [
            'L.pay (나이스페이먼츠)',
            'L.pay로 결제 (나이스페이먼츠)',
            'L.pay (NicePayments)',
            'Pay with L.pay (NicePayments)',
        ],
        ko: {
            heading: 'L.pay (나이스페이먼츠)',
            description: 'L.pay로 결제 (나이스페이먼츠)',
            title: 'L.pay로 결제 (나이스페이먼츠)',
        },
        en: {
            heading: 'L.pay (NicePayments)',
            description: 'Pay with L.pay (NicePayments)',
            title: 'Pay with L.pay (NicePayments)',
        },
        markText: 'L',
        markClassName: 'bg-purple-600 text-white',
    },
];

let observer: MutationObserver | null = null;
let retryTimer: number | null = null;
let syncQueued = false;

function windowRecord(): Record<string, unknown> {
    return window as unknown as Record<string, unknown>;
}

function isCheckoutPage(): boolean {
    return CHECKOUT_RE.test(window.location.pathname);
}

function normalizedText(value: string | null | undefined): string {
    return (value ?? '').replace(/\s+/g, ' ').trim();
}

function comparableText(value: string | null | undefined): string {
    return normalizedText(value).replace(/\u200B/g, '');
}

function isKoreanPage(): boolean {
    const lang = document.documentElement.lang || navigator.language || '';

    return lang.toLowerCase().startsWith('ko');
}

function copyFor(definition: EasyPayDefinition): EasyPayCopy {
    return isKoreanPage() ? definition.ko : definition.en;
}

function displayText(value: string): string {
    return value
        .replaceAll('네이버페이', '네이\u200B버페이')
        .replaceAll('카카오페이', '카카\u200B오페이')
        .replaceAll('삼성페이', '삼성\u200B페이')
        .replaceAll('Naver Pay', 'Naver\u200B Pay')
        .replaceAll('Kakao Pay', 'Kakao\u200B Pay')
        .replaceAll('Samsung Pay', 'Samsung\u200B Pay')
        .replaceAll('L.pay', 'L.\u200Bpay');
}

function findDefinitionById(id: string | undefined): EasyPayDefinition | null {
    return EASY_PAY_DEFINITIONS.find((definition) => definition.id === id) ?? null;
}

function findDefinitionForButton(button: HTMLButtonElement): EasyPayDefinition | null {
    const markedDefinition = findDefinitionById(button.dataset.nicepayEasyPayMethod);
    if (markedDefinition) return markedDefinition;

    const text = comparableText(button.textContent);

    return EASY_PAY_DEFINITIONS.find((definition) => (
        definition.labels.some((label) => text.includes(label))
    )) ?? null;
}

function findPaymentRow(button: HTMLButtonElement): HTMLElement | null {
    return button.querySelector<HTMLElement>('.flex.items-center.gap-2, .flex.items-center.gap-3')
        ?? button.querySelector<HTMLElement>('.flex.items-center');
}

function alignPaymentTextLeft(button: HTMLButtonElement, heading?: HTMLElement, description?: HTMLElement): void {
    button.style.textAlign = 'left';

    const row = findPaymentRow(button);
    if (row) {
        row.style.justifyContent = 'flex-start';
        row.style.textAlign = 'left';
        row.style.width = '100%';
    }

    const textContainer = heading?.parentElement ?? description?.parentElement;
    if (textContainer instanceof HTMLElement) {
        textContainer.style.textAlign = 'left';
        textContainer.style.minWidth = '0';
    }
}

function createMark(definition: EasyPayDefinition): HTMLSpanElement {
    const mark = document.createElement('span');
    mark.dataset.nicepayEasyPayMark = 'true';
    mark.dataset.nicepayEasyPayMethod = definition.id;
    mark.setAttribute('aria-hidden', 'true');
    mark.className = `inline-flex items-center justify-center rounded-lg text-xs font-bold ${definition.markClassName}`;
    mark.style.width = '32px';
    mark.style.height = '32px';
    mark.style.flex = '0 0 32px';
    mark.textContent = definition.markText;

    return mark;
}

function removeOtherPgArtifacts(button: HTMLButtonElement): void {
    delete button.dataset.kginicisBrandPaymentButton;
    delete button.dataset.kginicisBrandPaymentMethod;
    delete button.dataset.kginicisNaverpayBrandButton;
    delete button.dataset.nhnkcpEasyPayMethod;
    delete button.dataset.nhnkcpEasyPayVisible;
    delete button.dataset.nhnkcpEasyPayHidden;

    button.querySelectorAll<HTMLElement>('[data-kginicis-brand-payment-mark="true"], [data-kginicis-naverpay-mark="true"], [data-nhnkcp-easy-pay-mark="true"]').forEach((element) => {
        element.remove();
    });
}

function findPaymentIcon(button: HTMLButtonElement): Element | null {
    return button.querySelector('[data-nicepay-easy-pay-mark="true"]')
        ?? button.querySelector('[data-kginicis-brand-payment-mark="true"], [data-kginicis-naverpay-mark="true"], [data-nhnkcp-easy-pay-mark="true"]')
        ?? button.querySelector('svg')
        ?? button.querySelector('i[class*="fa-"], i[role="img"], i');
}

function ensureMark(button: HTMLButtonElement, definition: EasyPayDefinition): void {
    const existingMark = button.querySelector<HTMLElement>('[data-nicepay-easy-pay-mark="true"]');
    if (existingMark) {
        existingMark.dataset.nicepayEasyPayMethod = definition.id;
        existingMark.className = `inline-flex items-center justify-center rounded-lg text-xs font-bold ${definition.markClassName}`;
        existingMark.textContent = definition.markText;
        return;
    }

    const mark = createMark(definition);
    const icon = findPaymentIcon(button);
    if (icon && icon.parentElement) {
        icon.replaceWith(mark);
        return;
    }

    const row = findPaymentRow(button);
    row?.prepend(mark);
}

function updatePaymentText(button: HTMLButtonElement, definition: EasyPayDefinition): void {
    const paragraphs = Array.from(button.querySelectorAll<HTMLParagraphElement>('p'));
    const copy = copyFor(definition);
    const headingText = displayText(copy.heading);
    const descriptionText = displayText(copy.description);

    const heading = paragraphs[0];
    const description = paragraphs[1];

    if (heading && heading.textContent !== headingText) {
        heading.textContent = headingText;
    }
    if (heading) {
        heading.setAttribute('aria-label', copy.heading);
        heading.style.textAlign = 'left';
        heading.style.whiteSpace = 'normal';
        heading.style.wordBreak = 'keep-all';
        heading.style.overflowWrap = 'anywhere';
    }

    if (description && description.textContent !== descriptionText) {
        description.textContent = descriptionText;
    }
    if (description) {
        description.style.textAlign = 'left';
        description.style.fontSize = '12px';
        description.style.lineHeight = '1rem';
        description.style.whiteSpace = 'normal';
        description.style.wordBreak = 'keep-all';
        description.style.overflowWrap = 'anywhere';
    }

    alignPaymentTextLeft(button, heading, description);
    button.title = copy.title;
}

function showButton(button: HTMLButtonElement, definition: EasyPayDefinition): void {
    if (button.hidden) button.hidden = false;
    if (button.disabled) button.disabled = false;
    if (button.style.display === 'none') button.style.removeProperty('display');
    button.removeAttribute('aria-hidden');
    button.dataset.nicepayEasyPayMethod = definition.id;
    button.dataset.nicepayEasyPayVisible = 'true';

    removeOtherPgArtifacts(button);
    ensureMark(button, definition);
    updatePaymentText(button, definition);
}

function removeLegacyInjectedSection(): boolean {
    const legacySection = document.getElementById(LEGACY_CONTAINER_ID);
    if (!legacySection) return false;

    legacySection.remove();

    return true;
}

export async function syncRenderedCheckoutEasyPayMethods(
    root: ParentNode = document,
): Promise<boolean> {
    if (typeof window === 'undefined' || typeof document === 'undefined') return false;
    if (!isCheckoutPage()) return false;

    let changed = removeLegacyInjectedSection();

    root.querySelectorAll<HTMLButtonElement>('button').forEach((button) => {
        const definition = findDefinitionForButton(button);
        if (!definition) return;

        showButton(button, definition);
        changed = true;
    });

    return changed;
}

function queueSync(): void {
    if (syncQueued) return;
    syncQueued = true;

    window.setTimeout(() => {
        syncQueued = false;
        void syncRenderedCheckoutEasyPayMethods();
    }, 0);
}

function stopRetries(): void {
    if (retryTimer === null) return;

    window.clearInterval(retryTimer);
    retryTimer = null;
}

function startSync(): void {
    if (!isCheckoutPage()) return;

    stopRetries();
    void syncRenderedCheckoutEasyPayMethods();

    let attempts = 0;
    retryTimer = window.setInterval(() => {
        attempts += 1;
        void syncRenderedCheckoutEasyPayMethods();

        if (attempts >= SYNC_RETRY_ATTEMPTS) {
            stopRetries();
        }
    }, SYNC_RETRY_INTERVAL_MS);

    const body = document.body as HTMLElement & Record<string, unknown>;
    if (body[LISTENER_FLAG]) return;
    body[LISTENER_FLAG] = true;

    observer = new MutationObserver(() => queueSync());
    observer.observe(document.body, { childList: true, subtree: true, characterData: true });
}

function onRouteChange(): void {
    if (isCheckoutPage()) startSync();
}

export function installCheckoutEasyPayInjector(): void {
    if (typeof window === 'undefined' || typeof document === 'undefined') return;
    const w = windowRecord();
    if (w[FLAG]) return;
    w[FLAG] = true;

    console.info(`[${PLUGIN_ID}] checkout easy pay method sync installed`);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => startSync());
    } else {
        startSync();
    }

    const origPush = history.pushState.bind(history);
    history.pushState = (...args: Parameters<typeof history.pushState>) => {
        origPush(...args);
        window.setTimeout(() => onRouteChange(), 200);
    };
    window.addEventListener('popstate', () => window.setTimeout(() => onRouteChange(), 200));
}

export function resetCheckoutEasyPayInjectorForTests(): void {
    observer?.disconnect();
    observer = null;
    stopRetries();
    syncQueued = false;
    delete windowRecord()[FLAG];
    if (document.body) {
        delete ((document.body as HTMLElement & Record<string, unknown>)[LISTENER_FLAG]);
    }
}
