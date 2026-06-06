# TxtCow SMS Gateway - WordPress 플러그인

WooCommerce 쇼핑몰에서 TxtCow를 통해 고객에게 자동으로 SMS 알림을 전송하는 WordPress 플러그인입니다.

## 기능

- **자동 SMS 전송**: 주문 접수, 완료, 취소 시 자동으로 SMS 전송
- **맞춤 메시지**: 주문 상태별로 메시지 템플릿 설정 가능
- **변수 지원**: `{order_number}`, `{customer_name}`, `{total}` 변수 사용 가능
- **테스트 기능**: 설정 페이지에서 테스트 SMS 전송 가능
- **선택적 알림**: 원하는 주문 상태에만 SMS 전송 설정
- **주문 노트**: 각 주문에 SMS 전송 내역 기록

## 설치 방법

### 1. 플러그인 파일 업로드

#### FTP 사용 시:
1. `txtcow-sms` 폴더 전체를 WordPress 설치 폴더의 `wp-content/plugins/` 디렉토리에 업로드합니다.
   ```
   /wp-content/plugins/txtcow-sms/
   ```

#### WordPress 관리자 페이지 사용 시:
1. `txtcow-sms` 폴더를 ZIP 파일로 압축합니다.
2. WordPress 관리자 페이지에서 **플러그인 → 새로 추가 → 플러그인 업로드**를 클릭합니다.
3. ZIP 파일을 선택하고 **지금 설치**를 클릭합니다.

### 2. 플러그인 활성화

1. WordPress 관리자 페이지에서 **플러그인** 메뉴로 이동합니다.
2. **TxtCow SMS Gateway**를 찾아 **활성화**를 클릭합니다.

### 3. API 키 설정

1. TxtCow 웹사이트에 로그인합니다: https://txtcow.com
2. **Dashboard → Integrations → New Integration**을 클릭합니다.
3. Integration을 생성하고 표시되는 **API 키**를 복사합니다.
   - ⚠️ **중요**: API 키는 생성 시 한 번만 표시됩니다. 안전한 곳에 보관하세요!

4. WordPress 관리자 페이지에서 **설정 → TxtCow SMS**로 이동합니다.
5. **API 키** 필드에 복사한 키를 붙여넣고 **설정 저장**을 클릭합니다.

## 사용 방법

### 기본 설정

#### 1. API 설정 탭

- **API 키**: TxtCow에서 발급받은 API 키 입력
- **알림 활성화**:
  - ✅ **주문 접수 (Processing)**: 주문이 접수되면 SMS 전송
  - ☐ **주문 완료 (Completed)**: 주문이 완료되면 SMS 전송
  - ☐ **주문 취소 (Cancelled)**: 주문이 취소되면 SMS 전송

#### 2. 메시지 설정 탭

메시지에 사용할 수 있는 변수:
- `{order_number}`: 주문 번호
- `{customer_name}`: 고객 이름
- `{total}`: 주문 총액

**기본 메시지 템플릿**:
```
주문 접수: 주문이 접수되었습니다. 주문번호: {order_number}
주문 완료: 주문이 완료되었습니다. 주문번호: {order_number}
주문 취소: 주문이 취소되었습니다. 주문번호: {order_number}
```

**커스텀 메시지 예제**:
```
{customer_name}님, 주문이 접수되었습니다!
주문번호: {order_number}
결제금액: {total}
감사합니다.
```

#### 3. 테스트 SMS 탭

설정이 제대로 작동하는지 확인하기 위해 테스트 SMS를 전송할 수 있습니다.

1. **전화번호**: SMS를 받을 전화번호 입력 (예: 010-1234-5678)
2. **메시지**: 테스트 메시지 입력
3. **테스트 SMS 전송** 버튼 클릭

## 작동 방식

1. 고객이 WooCommerce 쇼핑몰에서 주문을 완료합니다.
2. 주문 상태가 변경될 때 (예: Processing, Completed, Cancelled)
3. 플러그인이 자동으로 TxtCow API를 통해 SMS를 전송합니다.
4. 연결된 안드로이드 디바이스가 실제 SMS를 발송합니다.
5. 주문 노트에 SMS 전송 내역이 기록됩니다.

## 주문 노트 확인

각 주문의 상세 페이지에서 SMS 전송 내역을 확인할 수 있습니다:

- **WooCommerce → 주문 → [특정 주문]**
- 하단의 **주문 노트** 섹션에서 SMS 전송 성공/실패 내역 확인

예시:
```
TxtCow SMS 전송 성공: 010-1234-5678
TxtCow SMS 전송 실패: HTTP 401: Unauthorized
```

## 문제 해결

### SMS가 전송되지 않을 때

1. **API 키 확인**
   - TxtCow Dashboard에서 API 키가 활성화되어 있는지 확인
   - WordPress 설정 페이지에 올바른 API 키가 입력되었는지 확인

2. **전송 제한 확인**
   - TxtCow Dashboard → Quota에서 전송 제한 초과 여부 확인
   - 현재 할당량: 3/분, 30/일, 930/월

3. **디바이스 상태 확인**
   - TxtCow Dashboard → Devices에서 안드로이드 디바이스가 온라인인지 확인
   - 디바이스의 TxtCow 앱이 실행 중인지 확인

4. **전화번호 형식**
   - 국제 형식: `+8201012345678`
   - 국내 형식: `010-1234-5678`
   - 두 형식 모두 지원됨

5. **주문 노트 확인**
   - 주문 상세 페이지에서 오류 메시지 확인

6. **WooCommerce 활성화**
   - WooCommerce 플러그인이 활성화되어 있는지 확인

### 오류 메시지

| 오류 | 의미 | 해결 방법 |
|------|------|-----------|
| `HTTP 401: Unauthorized` | API 키가 유효하지 않음 | API 키를 다시 확인하고 재입력 |
| `HTTP 429: Too Many Requests` | 전송 제한 초과 | TxtCow Dashboard에서 할당량 확인 |
| `전화번호가 없습니다` | 주문에 전화번호 미입력 | 고객이 결제 시 전화번호를 입력했는지 확인 |
| `API 키가 설정되지 않았습니다` | API 키 미설정 | 설정 페이지에서 API 키 입력 |

## 시스템 요구사항

- **WordPress**: 5.0 이상
- **WooCommerce**: 3.0 이상
- **PHP**: 7.2 이상
- **TxtCow 계정**: https://txtcow.com

## 지원

문제가 발생하거나 질문이 있으시면:

- **TxtCow 웹사이트**: https://txtcow.com
- **GitHub**: https://github.com/nzfreeman/txtcow

## 라이선스

GPL v2 or later

## 버전 정보

**현재 버전**: 1.0.0

### 변경 내역

#### 1.0.0 (2026-01-03)
- 최초 릴리스
- 주문 접수/완료/취소 시 SMS 자동 전송
- 메시지 템플릿 커스터마이징
- 테스트 SMS 전송 기능
- 주문 노트에 전송 내역 기록
