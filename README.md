# TxtCow SMS Gateway

WordPress plugin for WooCommerce stores that use TxtCow to send order SMS notifications.

## English

### What it does

- Sends SMS when orders are received, completed, or cancelled
- Lets you customize message templates per order status
- Supports variables like `{order_number}`, `{customer_name}`, and `{total}`
- Records delivery results in WooCommerce order notes
- Includes a built-in test SMS flow and connection status panel
- Supports English and Korean dashboard text

### Why TxtCow

TxtCow keeps SMS delivery tied to your own connected Android device instead of a generic gateway account. That gives you:

- Better control over message sending
- Centralized opt-out handling
- QR-based quick device pairing
- Store-level delivery logs and connection visibility
- A setup that fits WooCommerce stores that already run operations through TxtCow

### Requirements

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.2+
- A TxtCow account, dashboard access, and at least one connected TxtCow Android device

### Install

1. Install and sign in to TxtCow first at [txtcow.com](https://txtcow.com).
2. In TxtCow, connect your Android device and create an API key from **Dashboard -> Integrations**.
3. Upload the `txtcow-sms` folder to `wp-content/plugins/`, or upload the ZIP in WordPress admin.
4. Activate **TxtCow SMS Gateway** in WordPress.
5. Open **Settings -> TxtCow SMS** and paste your TxtCow API key.
6. Configure the notification options and save.

### Use

1. Enable the order statuses you want to notify.
2. Edit the message templates if needed.
3. Use the test SMS tab to verify delivery.
4. Check the connection status and order notes if something is not delivered.

### Practical benefits

- No manual SMS sending for common WooCommerce events
- Less switching between WordPress and your phone
- Clear delivery traces for each order
- Easier customer communication in both Korean and English stores

### Support

- TxtCow: [txtcow.com](https://txtcow.com)
- GitHub: [nzfreeman/txtcow-wordpress-plugin](https://github.com/nzfreeman/txtcow-wordpress-plugin)

## 한국어

### 이 플러그인은

WooCommerce 쇼핑몰에서 TxtCow를 사용해 주문 알림 SMS를 자동으로 보내는 WordPress 플러그인입니다.

### 주요 기능

- 주문 접수, 완료, 취소 시 자동 SMS 전송
- 주문 상태별 메시지 템플릿 수정 가능
- `{order_number}`, `{customer_name}`, `{total}` 같은 변수 지원
- 전송 결과를 WooCommerce 주문 노트에 기록
- 테스트 SMS 전송과 연결 상태 확인 화면 제공
- 관리자 화면 영어/한국어 표시 지원

### TxtCow를 함께 써야 하는 이유

이 플러그인은 TxtCow와 함께 써야 제대로 동작합니다. TxtCow는 단순한 발송 연결이 아니라, 실제 SMS 전송을 사용자의 연결된 Android 디바이스에 맡기기 때문에 운영 제어가 쉽습니다.

- 발송 흐름을 직접 통제 가능
- 수신 거부 번호를 중앙에서 관리
- QR 코드로 빠르게 디바이스 연결
- 스토어별 전송 로그와 연결 상태 확인 가능
- WooCommerce 운영 흐름에 맞게 확장하기 쉬움

### 설치 조건

- WordPress 5.0 이상
- WooCommerce 3.0 이상
- PHP 7.2 이상
- TxtCow 계정, TxtCow 대시보드 접근 권한, 연결된 TxtCow Android 디바이스 1대 이상

### 설치 방법

1. 먼저 [txtcow.com](https://txtcow.com)에서 TxtCow에 로그인합니다.
2. TxtCow에서 Android 디바이스를 연결하고 **Dashboard -> Integrations**에서 API 키를 생성합니다.
3. `txtcow-sms` 폴더를 `wp-content/plugins/`에 업로드하거나 WordPress 관리자에서 ZIP 파일로 업로드합니다.
4. WordPress에서 **TxtCow SMS Gateway**를 활성화합니다.
5. **설정 -> TxtCow SMS**로 이동해 TxtCow API 키를 입력합니다.
6. 알림 옵션을 설정하고 저장합니다.

### 사용 방법

1. 알림을 보낼 주문 상태를 선택합니다.
2. 필요하면 메시지 템플릿을 수정합니다.
3. 테스트 SMS 탭으로 실제 전송을 확인합니다.
4. 연결 상태와 주문 노트를 확인해 미전송 원인을 점검합니다.

### TxtCow의 장점

- 주문 처리에 필요한 SMS를 수동으로 보낼 필요가 줄어듭니다
- WordPress와 휴대폰을 오가며 작업하는 시간을 줄입니다
- 주문별 전송 내역을 남길 수 있습니다
- 한국어/영어 스토어 운영에 모두 맞습니다

### 지원

- TxtCow: [txtcow.com](https://txtcow.com)
- GitHub: [nzfreeman/txtcow-wordpress-plugin](https://github.com/nzfreeman/txtcow-wordpress-plugin)
