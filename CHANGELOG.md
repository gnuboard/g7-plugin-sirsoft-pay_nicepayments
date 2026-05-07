# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [Unreleased]

### Changed

- 플러그인 식별자를 `sirsoft-pay-nicepayments`에서 `sirsoft-pay_nicepayments`로 변경 — G7 코어가 권장하는 `vendor-name` 2-part 명명 규칙에 맞추기 위함
- 사용자 노출 에러/환불 메시지를 한국어/영어 다국어로 분리 — 운영 언어에 따라 자동 노출
- PG 프로바이더 표시명을 다국어 키로 분리 — 활성 언어팩으로 자동 보강되어 다른 PG 플러그인과 동일한 컨벤션으로 정렬

### Added

- `sirsoft-pay_nicepayments.payment.before_cancel` / `after_cancel` 액션 훅 — 외부 소비자가 결제 취소 지점에 본인인증 등 확장 로직을 붙일 수 있도록 확장점 제공
- 가상계좌 입금통보 IP 화이트리스트 검증 강화 — 공식 발송 IP 외 요청은 라우트 진입 단계에서 차단
- PG 도메인 전용 예외 도입 — 외부 소비자가 나이스페이먼츠 도메인 오류만 선택적으로 처리할 수 있도록 개선

### Fixed

- 다크 모드에서 결제 설정 폼 입력 필드의 포커스 링이 보이지 않던 문제 수정

## [1.0.0-beta.1] - 2026-04-22

### Added

- 오픈 베타 릴리즈
