# 13 — Dokümantasyon Doğruluğu ve Eksiksizliği

> PR #3 sistematik review · düşmanca doğrulamalı · 19 bulgu

## Özet

Bu PR'daki dokümantasyon hacim ve ton olarak çoğu WordPress eklentisinin üzerinde: her rehber "desteklenen zarf" (CARD/BANK/CELLPHONE, KRW, UTF-8) ve DG-01/DG-02 sertifikasyon kapılarıyla açılıyor, readme.txt gerçek bir üçüncü-taraf hizmet ifşası ve saklama politikası içeriyor, imza formülü tabloları ve preset tabloları kodla birebir eşleşiyor. Ancak dokümanların kodla eşleşmesi kritik noktalarda kopuyor: shortcode `buyer_name/buyer_email/buyer_tel` parametreleri üç ayrı dokümanda "ön-doldurma yapar" diye anlatılırken kod bunları koşulsuz olarak kayıtlı konfigürasyondan eziyor; `id` olmadan çalışan 1.x shortcode'ları artık hata basıyor ama hiçbir yerde kırıcı değişiklik olarak duyurulmuyor; ARCHITECTURE.md yeni eklenen 7 sınıfın çoğunu, 70 sütunlu şemanın 43 sütununu ve `nicepay_refund_attempts` tablosunu hiç tanımıyor. Ayrıca readme.txt'de `Contributors:` başlığı yok — bu, projenin kendi `deploy-wordpress-org.yml` publish işini (satır 262-265) sert şekilde durduracak bir eksik; `== Screenshots ==` bölümü ve `.wordpress-org/` asset dizini de yok. Kullanıcıya görünen birkaç özellik (System Report sekmesi, hazırlık paneli, CSV dışa aktarma, standalone makbuz linki/e-postası) hiçbir kullanıcı dokümanında geçmiyor; 7 public filtrenin 5'i tamamen belgesiz. Son olarak dil tutarlılığı bozuk: WORDPRESS-ORG-RELEASE.md ve 14 dosyalık docs/analysis tamamen Türkçe, geri kalan her şey İngilizce ve hedef kitle global.

## Değerlendirme

| Ölçüt | Değer |
|---|---|
| Genel not | **C** |
| 🔴 Kritik | 0 |
| 🟠 Yüksek | 4 |
| 🟡 Orta | 10 |
| 🔵 Düşük | 5 |
| ⚪ Bilgi | 0 |
| Düşmanca doğrulamada elenen | 0 |
| Doğrulama sonrası severity düzeltilen | 0 |
| Bağımsız doğrulama kararı alan bulgu | 12 / 19 |
| Tarama geçişi | 1 |
| Referans verilen dosya | 11 |

## Güçlü yönler

- readme.txt gerçek bir "External service disclosure" bölümü (satır 27-39), saklama politikası açıklaması (satır 33), 6 maddelik FAQ ve `== Upgrade Notice ==` içeriyor; kısa açıklama 116 karakter (<=150 sınırının altında) ve Tags tam 5 adet — WP.org limitlerine uygun.
- Test kimlik bilgisi iddiaları kodla birebir doğru: README.md:52-54, docs/CONFIGURATION.md:83-85 ve docs/USER-GUIDE.md:96-98'deki "pre-filled" ifadesi nicepay-payment-gateway.php:33-34 ve includes/class-nicepay-api.php:30-31 ile eşleşiyor.
- docs/CONFIGURATION.md:219-223'teki varsayılan preset tablosu (Quick Payment 10.000 / Donation 5.000 / Product Purchase 50.000, renkler ve modlar dahil) nicepay_get_default_presets() (includes/nicepay-functions.php:1735-1800) ile birebir uyuyor.
- İmza formülü tabloları (docs/API-REFERENCE.md:291-322, docs/DEVELOPER-GUIDE.md:147-154) gerçek implementasyonla (includes/class-nicepay-api.php:98-110) uyumlu ve "padded amount'u imza öncesi trim etme" uyarısı gerçek koda karşılık geliyor.
- Saklama (retention) semantiği iki doküman arasında tutarlı: docs/CONFIGURATION.md:73 "en fazla 500 kayıt/gün" ile docs/DEVELOPER-GUIDE.md:58 "100 satır x 5 batch" aynı sınırı anlatıyor.
- docs/USER-GUIDE.md:642'deki `:has()` desteklenmeyen tarayıcılar için JS fallback iddiası gerçek: assets/css/nicepay.css:127 `:has()` kullanıyor ve assets/js/nicepay.js:46-47 ile 454-457 `is-selected` fallback'ini uyguluyor.
- docs/WORDPRESS-ORG-RELEASE.md yayın workflow'unu doğru tarif ediyor: dry-run varsayılanı, 7/30 günlük artifact retention, 10up action'ın 2.3.0 tam SHA pin'i, Update URI kapısı ve slug=Text Domain kontrolü .github/workflows/deploy-wordpress-org.yml ile eşleşiyor.
- Sürüm tutarlılığı otomasyona bağlanmış: .github/scripts/check-version.js plugin header / NICEPAY_VERSION / CHANGELOG / test bootstrap / POT sürümlerini, deploy workflow ise readme.txt Stable tag, Requires at least, Requires PHP ve License'ı mekanik olarak doğruluyor.
- SECURITY.md gerçek bir GHSA özel bildirim URL'i veriyor ve rapora merchant key/PAN/token konulmamasını açıkça yasaklıyor; .github/ISSUE_TEMPLATE/bug_report.yml aynı uyarıyı zorunlu checkbox ile pekiştiriyor.

## Bulgular

### DOC-001 — readme.txt'de `Contributors:` başlığı yok — projenin kendi WordPress.org publish işi bu yüzden durur

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | wordpress-org-readiness |
| **Konum** | [readme.txt:1](../../../readme.txt#L1) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

````text
readme.txt başlık bloğu yalnızca şunları içeriyor (satır 1-8): `=== NicePay Payment Gateway ===`, `Tags:`, `Requires at least:`, `Tested up to:`, `Requires PHP:`, `Stable tag:`, `License:`, `License URI:`. `Contributors:` satırı, `Donate link:` ve `== Screenshots ==` bölümü hiç yok (`grep -n "Contributors\|Donate\|Screenshots" readme.txt` boş dönüyor). Buna karşılık .github/workflows/deploy-wordpress-org.yml:262-265 publish işinde sert bir kapı var:
```
if ! grep -Eq '^Contributors:[[:space:]]*[a-zA-Z0-9_.-]+' readme.txt; then
  echo 'ERROR: add and verify at least one real WordPress.org username in readme.txt Contributors before publishing.' >&2
  exit 1
fi
```
Ayrıca aynı workflow satır 298'de `ASSETS_DIR: .wordpress-org` tanımlıyor; repo kökünde `.wordpress-org/` dizini yok (docs/WORDPRESS-ORG-RELEASE.md:92 bunu bilinçli kabul ediyor ama readme.txt tarafındaki eksikliği bağlamıyor). docs/WORDPRESS-ORG-RELEASE.md:103 "Her `screenshot-N` dosyası için readme.txt içinde aynı numaralı bir ekran görüntüsü açıklaması olmalıdır" diyor, fakat readme.txt'de `== Screenshots ==` bölümü hiç bulunmuyor.
````

**Başarısızlık senaryosu**

Maintainer v2.0.0 etiketini atar, workflow'u `mode=publish`, `confirmation=PUBLISH:<slug>:2.0.0` ile çalıştırır. `prepare` işi başarıyla ZIP üretir, `wordpress-org-production` reviewer onayı verilir, secret'lar doğrulanır — ve "Verify production authorization and payload" adımı `ERROR: add and verify at least one real WordPress.org username in readme.txt Contributors before publishing.` ile exit 1 döner. Yayın yapılamaz.

**Etki**

Yayın günü, publish modundaki workflow korumalı environment onayından SONRA, SVN'e yazmadan hemen önce hata ile durur. Onay/rollout süreci boşa harcanır ve eksiklik yalnızca CI log'unda görülür; hiçbir doküman "readme.txt şu anda Contributors içermiyor, yayından önce eklenmeli" demiyor — sadece kontrol listesinde bir kutucuk var (docs/WORDPRESS-ORG-RELEASE.md:177).

**Öneri**

1) readme.txt başlık bloğuna onaylı WordPress.org kullanıcı adını ekleyin (tahmini GitHub adı değil):
```
=== NicePay Payment Gateway ===
Contributors: <onayli-wporg-kullanici-adi>
Tags: woocommerce, payment gateway, credit card, bank transfer, mobile payments
...
```
2) `== Screenshots ==` bölümü ekleyin veya en azından "görsel yok" durumunu readme'de değil sadece yayın rehberinde bırakmak yerine, `.wordpress-org/` dizinini boş bir `README` ile oluşturup görsel gereksinimini takip edilebilir hale getirin.
3) docs/WORDPRESS-ORG-RELEASE.md "Workflow'un güvenlik modeli > Publish" listesine (satır 158-168) mevcut Contributors kapısını 5. maddeden sonra ekleyin ki kapı sadece kontrol listesinde değil, akış tarifinde de görünsün.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: readme.txt'de `Contributors:` başlığı yok; projenin kendi publish kapısı (.github/workflows/deploy-wordpress-org.yml:262-265) bunu zorunlu kıldığı için, eksiklik tag atılmadan giderilmezse publish job'ı korumalı environment onayından sonra durur. Kapı fail-closed ve etiketlenen sürümün readme.txt'sini okuduğundan (workflow:222 `ref: refs/tags/v${version}`) yanlış yayına yol açmaz; gerçek kusur bir onay döngüsünün israfı ve eksikliğin readme.txt'nin kendisinde görünmemesidir. Ek olarak, Contributors kapısı docs/WORDPRESS-ORG-RELEASE.md'nin "Publish" akış tarifinde (satır 156-164) listelenmemiş — yalnızca satır 177'deki kontrol listesi maddesinde geçiyor. İddianın `== Screenshots ==` bölümüne dair yan unsuru GEÇERSİZDİR: docs:103 kuralı screenshot dosyası başına koşulludur ve repoda hiç screenshot yoktur (docs:92 bunu bilinçli olarak gerekçelendirir), dolayısıyla bir doküman-kod uyuşmazlığı yoktur. İddianın "hiçbir doküman bunu söylemiyor" etkisi de yanlıştır: docs:177 tam olarak bu gereksinimi (tahmini GitHub adı kullanmama uyarısıyla birlikte) yayın öncesi zorunlu adım olarak yazıyor.
- Gerekçe: Çekirdek iddia TAMAMEN doğrulandı ve satır numaraları dosyaların şu anki haliyle birebir eşleşiyor. Çürütecek bir koruma aradım (prepare işinde erken kontrol, tag öncesi otomatik enjeksiyon, başka bir readme kaynağı) — YOK. Ancak iddianın üç yan unsuru zayıf/yanlış, bu yüzden "confirmed" değil "partially-confirmed":

(1) DOĞRULANAN ÇEKİRDEK: readme.txt başlık bloğunda (satır 1-8) `Contributors:` yok; `grep -n "Contributors\|Donate\|Screenshots" readme.txt` gerçekten exit 1 (boş) dönüyor. Workflow satır 262-265'te tam olarak iddia edilen sert kapı var. Kapının ait olduğu `publish` job'ı satır 208-216'da `if: inputs.mode == 'publish'`, `needs: prepare`, `environment: name: wordpress-org-production` ile tanımlı. GitHub environment onayı JOB başlamadan önce beklediği için, kapı gerçekten reviewer onayından SONRA çalışır. Kapı, SVN'e dokunan adımlardan (satır 287 `svn info`, satır 291 `10up/action-wordpress-plugin-deploy`) ÖNCE olduğu için "onaydan sonra, SVN yazımından hemen önce durur" ifadesi doğru. `prepare` job'ının readme doğrulama bloğunu (satır 95-125) okudum: yalnızca Stable tag / Requires at least / Requires PHP / License tutarlılığını kontrol ediyor — Contributors'a hiç bakmıyor, yani hata erken yakalanmıyor. `.wordpress-org/` dizini de gerçekten yok (repo kökü dotdir listesi: .codacy.yml, .editorconfig, .git, .gitattributes, .github, .gitignore, .phpunit.result.cache).

(2) YANLIŞ YAN İDDİA — Screenshots: İddia, docs/WORDPRESS-ORG-RELEASE.md:103 ile readme.txt arasında bir uyuşmazlık olduğunu söylüyor. Satırı okudum: "Her `screenshot-N` dosyası için `readme.txt` içinde aynı numaralı bir ekran görüntüsü açıklaması olmalıdır." Bu KOŞULLU bir kural — screenshot dosyası varsa geçerli. `.wordpress-org/` dizini ve dolayısıyla hiçbir `screenshot-N` dosyası yok, üstelik satır 92 bunu bilinçli olarak gerekçelendiriyor ("hazır ve hakları doğrulanmış görsel olmadığı için sahte veya geçici binary artwork eklenmemiştir"). Sıfır screenshot için sıfır açıklama gerekir; `== Screenshots ==` bölümünün yokluğu bir tutarsızlık DEĞİL, tutarlı davranıştır. Ayrıca `Contributors` ve `Donate link` WordPress.org readme spesifikasyonunda zorunlu değildir; bloke eden şey spec değil, projenin kendi 262. satırdaki kapısıdır.

(3) ABARTILAN ETKİ — "hiçbir doküman ... demiyor": docs/WORDPRESS-ORG-RELEASE.md:177 satırını okudum, aynen şunu diyor: "- [ ] Onaylı WordPress.org contributor kullanıcı adları `readme.txt` dosyasına eklendi; tahmini GitHub kullanıcı adı kullanılmadı." Bu, iddianın kendi önerdiği düzeltmenin (tahmini GitHub adı kullanma dahil) birebir dokümante edilmiş hali ve "İlk yayın kontrol listesi" başlığı altında, yayın öncesi zorunlu adım olarak duruyor. İddianın "sadece bir kutucuk var" küçümsemesi, bunun tam da bir yayın-hazırlık kontrol listesi olduğu gerçeğini görmezden geliyor.

(4) SEVERITY DÜZELTMESİ — high değil medium: (a) Kapı fail-closed; yanlış/eksik bir paketin WordPress.org'a gitmesini ENGELLİYOR, hatalı yayına sebep olmuyor. (b) Publish job'ı satır 222'de `ref: refs/tags/v${{ inputs.version }}` ile TAG'i checkout ediyor — yani kapı development HEAD'i değil, etiketlenen sürümün readme.txt'sini okuyor; kontrol listesini takip eden bir maintainer tag atmadan önce satırı eklerse kapı hiç tetiklenmez. (c) Maliyet tek bir onay döngüsünün israfı ve tek satırlık bir düzeltme. Gerçek kusur "yayın bloke olur" değil, "eksiklik CI hata mesajından önce readme.txt'nin kendisinde görünmüyor" — yani bir DX/izlenebilirlik pürüzü.

Öneri kısmındaki 1. ve 3. maddeler geçerli ve uygulanabilir; 2. madde (Screenshots bölümü ekleme) gereksiz — görsel yokken sahte bir bölüm eklemek WordPress.org readme validator'ı açısından da yanlış olur, yalnızca `.wordpress-org/` için placeholder README fikri savunulabilir.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `low`
- Düzeltilmiş iddia: Publish-öncesi eksiklikler yalnızca korumalı environment onayından SONRA yüzeye çıkıyor; dry-run bunları önceden gösteremiyor.

Doğru çerçeve: `publish` job'ı (deploy-wordpress-org.yml:208-216) job-level `wordpress-org-production` environment'ına bağlı olduğu için, satır 230'daki "Verify production authorization and payload" adımı reviewer onayından sonra çalışır. Bu adımda iki sert kapı var: satır 257-260 (`Update URI`) ve satır 262-265 (`Contributors`). Mevcut HEAD'de HER İKİSİ de tetiklenir — nicepay-payment-gateway.php:5 hâlâ `Update URI` içeriyor ve readme.txt:1-8 `Contributors:` içermiyor. Sıra gereği ilk hata Update URI mesajıdır, Contributors mesajı değil.

Asıl kusur, "readme.txt'de Contributors eksik" olması değil (bu bilinçli: gerçek WordPress.org kullanıcı adı ancak inceleme onayından sonra bilinir ve docs/WORDPRESS-ORG-RELEASE.md:177 bunu açıkça talimatlandırıyor). Asıl kusur ASİMETRİ: `prepare` job'ı Update URI için satır 136-138'de bilinçli bir `::warning::` ön-uyarısı üretirken, Contributors kapısının dry-run muadili hiç yok. Kontrol listesi (docs:189) "önce dry-run çalıştır" derken, dry-run Contributors eksikliğini asla raporlayamaz. Üstelik publish `refs/tags/v<version>` checkout ettiği için düzeltme yeniden tag gerektirir.

Düzeltme: prepare job'ının "Verify tag ancestry and release metadata" adımına, satır 136-138 ile simetrik bir preflight ekleyin:
```
if ! grep -Eq '^Contributors:[[:space:]]*[a-zA-Z0-9_.-]+' readme.txt; then
  echo '::warning::readme.txt has no Contributors entry; publish mode will hard-fail until a verified WordPress.org username is added.'
fi
```
Böylece her iki publish kapısı da dry-run'da görünür ve onay/tag döngüsü boşa gitmez.

İddianın Screenshots / `.wordpress-org` alt-iddiaları geçersizdir: docs:92 görsel yokluğunu bilinçli olarak beyan ediyor, docs:103'teki kural koşulludur ve sıfır screenshot dosyasıyla boş-doğrudur.
- Gerekçe: ÇEKİRDEK OLGU DOĞRU, AMA SENARYO VE SEVERITY YANLIŞ.

Doğrulananlar (kendim okudum):
1. readme.txt başlık bloğunda (satır 1-8) gerçekten `Contributors:` yok; `grep -n "Contributors\|Donate\|Screenshots" readme.txt` exit=1 döndü. Doğru.
2. .github/workflows/deploy-wordpress-org.yml:262-265'teki sert kapı gerçekten var. Doğru.
3. Kapı, job seviyesinde `environment: wordpress-org-production` (satır 214-216) tanımlı `publish` job'ının içinde, ilk adımlardan biri (satır 230). GitHub'da job-level environment onayı job'ın TÜM adımlarından önce beklendiği için, kapı gerçekten "reviewer onayından SONRA" çalışır. Doğru.
4. `prepare` (dry-run) job'ında Contributors kontrolü YOK — grep tüm repoda yalnız satır 262'yi buldu. prepare readme metadata'sını satır 103-134 arasında ayrıntılı doğruluyor ama Contributors'a bakmıyor. Bu, iddianın en sağlam ve en değerli kısmı ve iddia bunu açıkça söylemiyor bile.

ÇÜRÜTÜLENLER:

A) failure_scenario'nun somut çıktısı YANLIŞ. `nicepay-payment-gateway.php:5` şu anda `* Update URI: https://github.com/cemililik/...` içeriyor. Publish job'ında Update URI kapısı satır 257-260'ta, Contributors kapısından (262) ÖNCE. Yani iddianın betimlediği koşuda workflow `ERROR: remove the third-party Update URI before publishing through WordPress.org.` ile durur; iddianın alıntıladığı Contributors mesajı hiç görünmez. Reprodüksiyon adımları bu haliyle üretilemez.

B) "Hiçbir doküman 'Contributors eklenmeli' demiyor" iddiası yanlış. docs/WORDPRESS-ORG-RELEASE.md:177 birebir şunu diyor: "Onaylı WordPress.org contributor kullanıcı adları `readme.txt` dosyasına eklendi; tahmini GitHub kullanıcı adı kullanılmadı." Bu tam olarak iddianın "kimse söylemiyor" dediği şey; üstelik iddianın kendi `öneri` maddesindeki uyarıyla ("tahmini GitHub adı değil") aynı cümle. İddia kendi kanıtını çürütüyor.

C) Screenshots / `.wordpress-org` "doküman-kod uyuşmazlığı" iddiası yanlış. docs:92 bilinçli olarak "Bu projede hazır ve hakları doğrulanmış görsel olmadığı için sahte veya geçici binary artwork eklenmemiştir" diyor. docs:103'teki kural KOŞULLU: "Her `screenshot-N` dosyası için...". Sıfır screenshot dosyası olduğu için kural boş-doğru (vacuously true). `ASSETS_DIR: .wordpress-org` (satır 298) mevcut olmayan bir dizini gösteriyor ama 10up action'ı için bu hata değil, "yüklenecek görsel yok" demek. Ortada tutarsızlık yok.

D) SEVERITY ABARTILMIŞ: high → low. Sonuç lens'iyle bakınca:
- Kapı fail-CLOSED. SVN'e hiçbir yazma yapılmadan, deploy adımından (291) önce durur. Kısmi publish, bozuk trunk, güvenlik veya müşteri etkisi YOK. Bu, çalışan bir korumanın çalışması.
- Erişilebilirlik önkoşulları ağır: publish yolunun oraya gelmesi için WordPress.org incelemesinin bitmiş, `WPORG_PLUGIN_SLUG` environment variable'ının eklenmiş (247), SVN secret'larının konmuş (252) ve gerçek SVN deposunun var olması (287) gerekir. Bunların hepsi ancak inceleme onayından sonra mümkün — ki gerçek WordPress.org kullanıcı adı da tam o noktada belli olur. Yani kapı, bilgi henüz mevcut değilken erken patlayamaz.
- Gerçek maliyet: yakılmış bir reviewer onayı + readme.txt'e tek satır + yeniden tag (publish `refs/tags/v<version>` checkout ettiği için düzeltme yeni tag gerektirir). Can sıkıcı ops sürtünmesi, "high" değil.

GERÇEK VE SAVUNULABİLİR BULGU (iddianın söylemediği asıl kusur): Aynı workflow, Update URI için prepare/dry-run aşamasında bilinçli bir ön-uyarı üretiyor — satır 136-138: `echo '::warning::The GitHub Update URI must be removed ... before publish mode can proceed.'`. Contributors kapısının böyle bir dry-run muadili YOK. Kontrol listesi satır 189 "önce dry-run çalıştır" diyor, ama dry-run bu eksikliği asla yüzeye çıkaramaz. Asimetri budur; düzeltme de "readme'ye Contributors ekleyin" değil, prepare job'ına simetrik `::warning::` preflight eklemektir.

---

### DOC-002 — 2.0.0 major sürümü için yükseltme rehberi ve kırıcı değişiklik uyarısı yok; CHANGELOG mevcut kurulumları "fresh" diyerek yanlış güvenceye alıyor

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | breaking-change-documentation |
| **Konum** | [CHANGELOG.md:26](../../../CHANGELOG.md#L26) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```markdown
CHANGELOG.md:26: "Fresh WooCommerce and standalone payment entry points are disabled by default." — "Fresh" kelimesi yalnızca yeni kurulumları ima ediyor. Gerçekte kırıcı davranış mevcut kurulumları da vuruyor:
(a) `nicepay_standalone_enabled` opsiyonu 1.x'te hiç yoktu; nicepay-payment-gateway.php:395 `if ( 'yes' !== get_option( 'nicepay_standalone_enabled', 'no' ) )` varsayılanı 'no'. Eklenti güncellemesi activation hook'unu çalıştırmaz, dolayısıyla eski kurulumda çalışan tüm standalone formlar güncellemeden sonra "Standalone NicePay payments are not enabled." basar.
(b) `id` artık zorunlu: nicepay-payment-gateway.php:409 `return '<p class="nicepay-error">' . esc_html__( 'A saved payment configuration is required.' ) ...`. origin/main:225-263'teki eski handler `id` olmadan `amount`/`goods_name` attribute'larıyla tam çalışıyordu.
(c) Şema yükseltmesi tamamen bloklanabilir: includes/class-nicepay-installer.php:139-160 yinelenen Moid veya TID bulursa `nicepay_schema_duplicate_moid` / `nicepay_schema_duplicate_tid` WP_Error döner, `NicePay_Installer::is_current()` false kalır ve hem gateway hem shortcode kapanır. Hiçbir dokümanda (CHANGELOG, README, readme.txt `== Upgrade Notice ==`, docs/USER-GUIDE.md Troubleshooting) bu durum, belirtisi veya düzeltme adımı geçmiyor — `grep -rn "duplicate" README.md readme.txt docs/*.md` yalnızca alakasız "duplicate callbacks" ifadesini buluyor (CHANGELOG.md:46).
readme.txt:103 Upgrade Notice ise sadece "Review all settings after upgrading." diyor.
```

**Başarısızlık senaryosu**

Sitede `[nicepay_payment amount="5000" goods_name="Bagis"]` içeren bir bağış sayfası var ve 1.x altında çalışıyor. Yönetici eklentiyi 2.0.0'a günceller. Sayfa artık ödeme formu yerine "A saved payment configuration is required." paragrafı gösterir; standalone anahtarı da 'no' olduğu için doğru `id` eklense bile "Standalone NicePay payments are not enabled." çıkar. CHANGELOG'da "Fresh ... disabled by default" yazdığı için yönetici bunun kendisini etkilemeyeceğini varsaymıştır.

**Etki**

1.x'ten yükselten bir satıcı, güncelleme sonrası standalone ödeme sayfalarının sessizce kapandığını, `id`siz shortcode'ların hata metni bastığını veya (yinelenen Moid varsa) tüm NicePay ödemelerinin "temporarily unavailable" olduğunu üretimde keşfeder. Hiçbir dokümanda ne bekleneceği ve nasıl düzeltileceği yazmıyor.

**Öneri**

1) CHANGELOG.md'ye 2.0.0 altında ayrı bir `### Breaking changes` bölümü ekleyin ve en az şunları listeleyin: `[nicepay_payment]` artık kayıtlı `id` zorunlu kılıyor; `amount`/`goods_name`/`currency`/`pay_method`/`buyer_*` attribute'ları artık yok sayılıyor; standalone formlar yükseltmeden sonra kapalı gelir ve NicePay > Settings > Payment Methods'tan yeniden açılmalıdır; VBANK/SSG_BANK/GIFT_CULT yeni ödeme başlatamaz; legacy PAN/token verisi geri alınamaz şekilde temizlenir.
2) `docs/UPGRADE-2.0.md` (veya USER-GUIDE'a "Upgrading from 1.x" bölümü) ekleyin: yükseltme öncesi yedek, `id`siz shortcode'ların taranması, standalone anahtarının açılması, yinelenen Moid/TID'in tespiti (`SELECT moid, COUNT(*) FROM wp_nicepay_transactions GROUP BY moid HAVING COUNT(*)>1`) ve çözülmesi.
3) readme.txt `== Upgrade Notice ==` metnini bu üç somut kırıcı değişikliği adlandıracak şekilde güncelleyin.
4) docs/USER-GUIDE.md Troubleshooting tablosuna "NicePay payments are temporarily unavailable" satırı ekleyin ve şema migrasyonunun bloklanmış olabileceğini yazın.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: 2.0.0 için ayrı bir "Breaking changes" bölümü ve 1.x yükseltme rehberi yok; CHANGELOG.md:26'daki "Fresh ... disabled by default" ifadesi mevcut kurulumları da etkileyen davranışı yalnızca yeni kurulumlara özgüymüş gibi gösteriyor. Dokümante EDİLMEYEN üç kırıcı değişiklik: (1) `[nicepay_payment]` artık kayıtlı `id` zorunlu kılıyor ve `amount`/`goods_name`/`buyer_*`/`currency` attribute'ları yok sayılıyor (origin/main:248 `if ( ! empty( $raw_atts['id'] ) )` opsiyoneldi -> yeni 408-410 zorunlu); (2) 1.x'te var olmayan `nicepay_standalone_enabled` opsiyonu yalnızca aktivasyonda (satır 163/277) yazıldığı, güncelleme ise activation hook'unu tetiklemediği için mevcut standalone formlar güncelleme sonrası kapalı geliyor; (3) yinelenen Moid/TID varsa şema migrasyonu bloklanıp (installer 139-161) tüm NicePay ödeme yüzeyleri kapanıyor — hiçbir dokümanda tespit/çözüm adımı (`SELECT moid, COUNT(*) ... HAVING COUNT(*)>1`) yok.

Orijinal iddiadan DÜZELTİLEN iki nokta:
- Başarısızlık senaryosundaki mesaj sırası yanlış. Gate sırası standalone(395) -> schema(399) -> id(408) olduğu için `id`siz bir bağış sayfası güncelleme sonrası "A saved payment configuration is required." DEĞİL, "Standalone NicePay payments are not enabled." basar; `id` hatası ancak standalone yeniden açıldıktan sonra görünür.
- "Yönetici ne bekleyeceğini hiç bilemez" fazla güçlü: bloklanmış migrasyon için nicepay-payment-gateway.php:248-251'de belirtiyi ve ilk adımı adlandıran bir admin notice ("Review duplicate transaction references and the server error log.") ve satır 207'de bir error log kaydı var; readme.txt:97 ve :103 de jenerik olarak "payment entry points disabled by default" / "Payment methods remain disabled until explicitly configured" diyor. Eksik olan, bu üç kırıcı değişikliğin ADLANDIRILMASI ve adım adım yükseltme rehberi.
- Gerekçe: Bulgunun ÇEKİRDEĞİ doğrulandı: iddia edilen tüm satır numaraları dosyaların şu anki haliyle birebir eşleşiyor ve çürütücü bir koruma bulamadım.

(a) DOĞRU. `nicepay_standalone_enabled` origin/main'de hiç yok (`git show origin/main:nicepay-payment-gateway.php | grep standalone_enabled` -> 0 sonuç). Yeni kodda varsayılan 'no' ve opsiyon SADECE `set_default_options()` içinde (satır 277) yazılıyor; bu da yalnızca `activate_current_site()` (satır 163) üzerinden, yani `register_activation_hook` (satır 811) ve multisite `install_new_site` (satır 230) yolundan çağrılıyor. `plugins_loaded` üzerinde çalışan tek şey `maybe_install_schema()` (satır 202-203) ve o sadece şema kuruyor, opsiyon seed etmiyor. WordPress eklenti güncellemesi activation hook'unu tetiklemez -> mevcut 1.x kurulumunda shortcode'lar güncelleme sonrası kapanıyor. Ayrıca `nicepay_get_configuration_warnings()` (includes/nicepay-functions.php:494-532) bu durum için HİÇBİR admin uyarısı üretmiyor; standalone kapalıysa sadece uyarı listesinden düşüyor.

(b) DOĞRU. origin/main'deki eski handler (225-264) `id` olmadan çalışıyordu: satır 248 `if ( ! empty( $raw_atts['id'] ) )` — yani `id` tamamen opsiyoneldi, `amount`/`goods_name`/`buyer_*`/`currency` doğrudan `shortcode_atts` ile şablona gidiyordu (satır 259-262). Yeni handler satır 407-410'da `id` yoksa hata basıyor.

(c) DOĞRU. includes/class-nicepay-installer.php:138-161 yinelenen Moid/TID'de WP_Error dönüyor; nicepay-payment-gateway.php:399-401 ve 635 bu durumda gateway/shortcode'u kapatıyor. `grep -rni "duplicate" README.md readme.txt docs/*.md CHANGELOG.md` yalnızca CHANGELOG.md:46'daki alakasız "duplicate callbacks" ifadesini buluyor. docs/USER-GUIDE.md:636-646 Troubleshooting tablosunda "temporarily unavailable"/bloklanmış migrasyon satırı yok. docs/ altında UPGRADE dosyası yok; `grep -rni "upgrad" README.md docs/USER-GUIDE.md ...` sadece DEVELOPER-GUIDE.md:47'yi buluyor; "1.x"/"migrat" araması USER-GUIDE ve README'de hiçbir yükseltme rehberi bulmuyor.

ANCAK iki detay abartılı/yanlış, bu yüzden "confirmed" değil "partially-confirmed":

1) BAŞARISIZLIK SENARYOSU SIRALAMASI YANLIŞ. Gate sırası: standalone (395) -> is_current (399) -> id (408). Bağış sayfası örneğinde admin ASLA "A saved payment configuration is required." görmez; standalone kapalı olduğu için tek görülen mesaj "Standalone NicePay payments are not enabled." olur. `id` hatası ancak standalone açıldıktan SONRA ortaya çıkar. İddia iki mesajı ters sırayla anlatıyor.

2) "Hiçbir uyarı yok" iki noktada fazla güçlü:
   - Bloklanmış migrasyon için ÜRÜN İÇİ açık bir admin bildirimi var: nicepay-payment-gateway.php:243-252 `schema_error_notice()` "NicePay payments are unavailable because the transaction database could not be upgraded safely. Review duplicate transaction references and the server error log." Doküman boşluğu gerçek, ama "yönetici ne olduğunu hiç bilemez" doğru değil; belirti ürün içinde adlandırılıyor.
   - readme.txt:97 ve :103 (WP admin'in gösterdiği metin) zaten "Kept all payment entry points disabled by default" ve "Payment methods remain disabled until explicitly configured" diyor. Bu, (a)'nın genel şeklini kapsıyor — CHANGELOG.md:26'daki "Fresh" kelimesinin yarattığı yanlış güvenceyi kısmen dengeliyor. Eksik olan, shortcode `id` zorunluluğu ve yinelenen Moid senaryosunun ADLANDIRILMASI.

Bu yüzden severity'yi high -> medium'a düşürüyorum: gerçek bir doküman eksiği ama (i) davranış fail-closed ve her hata yolu kullanıcıya açık bir metin basıyor, (ii) readme.txt Upgrade Notice jenerik de olsa "ayarları gözden geçir / ödeme yöntemleri kapalı" uyarısını veriyor, (iii) veri kaybı veya sessiz para hareketi yok. Önerilen düzeltmeler (Breaking changes bölümü, UPGRADE-2.0, Troubleshooting satırı) geçerli ve uygulanabilir.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `low`
- Düzeltilmiş iddia: CHANGELOG.md:26's "Fresh ... disabled by default" wording understates the change (the gateway `enabled` default also flipped 'yes'->'no' at includes/class-nicepay-gateway.php:29, so an install that never explicitly saved WooCommerce settings goes dark on update), and there is no "Breaking changes" section, no upgrade note, and no troubleshooting row for a duplicate-Moid/TID-blocked migration. However, the finding's failure scenario — a merchant upgrading a live 1.x site — is not reproducible: `NICEPAY_VERSION` has been '2.0.0' in every commit since the initial `ee7d090`, there are zero git tags and zero GitHub releases, and `readme.txt` does not exist on `main`, so no 1.x was ever released or published. The affected population is unreleased-`main` GitHub installs, not production merchants, and a blocked migration does raise an admin_notices error (nicepay-payment-gateway.php:125, :248-251) plus an error-level log (:207), so it is not silent. The accurate residual defect is the inverse of the one claimed: CHANGELOG.md:56-58 asserts a "[1.x] - Previous Versions ... See Git history for details" that git history does not contain, and readme.txt:99-103 ships an `== Upgrade Notice ==` for a plugin with no prior release. Fix by deleting the fabricated 1.x stub and stating 2.0.0 is the first release, adding a short "Changes since the pre-release snapshot" note covering the `id` requirement, the standalone/gateway disable-on-update, and the possible duplicate-Moid migration block, and adding one USER-GUIDE troubleshooting row for "NicePay payments are temporarily unavailable".
- Gerekçe: The three CODE facts the finding rests on are all real, and I confirmed each one line by line. The DOCUMENTATION gap is also real: there is no breaking-change section, no upgrade guide, and no troubleshooting entry for a blocked migration.

What collapses under the exploitability lens is the failure_scenario and therefore the severity. The scenario requires "a merchant running 1.x upgrades to 2.0.0". That upgrade path does not exist and cannot be produced:

1. `NICEPAY_VERSION` is `'2.0.0'` in EVERY commit that ever touched the plugin file, including the initial `ee7d090 Add NicePay Payment Gateway integration`. I enumerated it mechanically over all 7 commits touching the file — no 1.x string has ever existed.
2. `git tag` returns nothing, `git ls-remote --tags origin` returns nothing, `gh release list` returns nothing. Zero releases anywhere.
3. `git show origin/main:readme.txt` is empty — `readme.txt` (the WordPress.org directory file) is NEW in this PR, so the plugin has never been on WordPress.org either.

So the population of "1.x merchants" is empty. The affected population is instead "anyone running the unreleased `main` snapshot from GitHub" — real, but developers/early adopters, not production merchants who "discover it in production", which is what drove the `high`.

Two further corrections to the finding's own text:

- It says "Hiçbir dokümanda ne bekleneceği ve nasıl düzeltileceği yazmıyor" for the blocked migration. Docs, yes — but IN-PRODUCT there IS an admin notice the finding never read: nicepay-payment-gateway.php:125 hooks `schema_error_notice`, which at :248-251 prints "NicePay payments are unavailable because the transaction database could not be upgraded safely. Review duplicate transaction references and the server error log." Plus `nicepay_log(...'error')` at :207. The failure is not silent; it is under-documented.
- The finding's own sub-premise "Eklenti güncellemesi activation hook'unu çalıştırmaz" is true but only load-bearing for the standalone option (where it changes nothing, since `get_option(..., 'no')` defaults to 'no' regardless). It is NOT true for the schema: `add_action( 'plugins_loaded', array( $this, 'maybe_install_schema' ), 5 )` at :94 runs the migration on every load, no activation needed.

The finding also MISSED the strongest instance of its own thesis, which I found while checking: `includes/class-nicepay-gateway.php:29` is now `$this->enabled = $this->get_option( 'enabled', 'no' )` where `origin/main:29` was `'yes'`. Anyone who ran main's gateway without ever explicitly saving WooCommerce settings has no stored `enabled` key, so the gateway flips off on update — the exact "not just fresh installs" case the CHANGELOG wording hides.

And there is an INVERTED documentation defect at the same location that is more defensible than the one claimed: CHANGELOG.md:56-58 asserts "## [1.x] - Previous Versions / Legacy versions with the initial NicePay integration. See Git history for details." That history does not exist. Likewise readme.txt:99-103 ships an `== Upgrade Notice ==` for a plugin that has never had a prior release. The accurate fix is not to write an UPGRADE-2.0 guide for a nonexistent 1.x, but to delete the fabricated 1.x stub and state that 2.0.0 is the first release.

Recommendation items 2 and 4 of the original finding are therefore misdirected (an upgrade guide for a phantom version, and a query against `wp_nicepay_transactions` that a fresh install will never need). Item 1's "Breaking changes" list is still worth keeping — but as a "changes since the pre-release GitHub snapshot" note, not a 1.x migration path.

Residual uncertainty, stated openly: if the maintainer distributed a 1.x build outside this repository (direct zip to a client, a private site), the original scenario becomes reachable and severity returns to medium. Nothing in the repo evidences that, and the CHANGELOG's own "See Git history for details" pointer resolves to nothing.

---

### DOC-003 — Shortcode `buyer_name`/`buyer_email`/`buyer_tel` parametreleri üç dokümanda "ön-doldurma yapar" diye anlatılıyor; kod bunları koşulsuz eziyor

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | code-doc-mismatch |
| **Konum** | [README.md:106](../../../README.md#L106) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

````markdown
README.md:106-108 shortcode parametre tablosu: `| buyer_name | No | — | Pre-fill buyer name (if empty, buyer fills in the form) |` (aynı şekilde buyer_email, buyer_tel) ve README.md:115: "When provided, those fields are pre-filled and hidden." README.md:117 sadece `amount`, `goods_name`, `currency` ve pay_method politikasının sunucu otoritesinde olduğunu söylüyor — buyer alanları bilinçli olarak bu listenin DIŞINDA bırakılmış.
docs/USER-GUIDE.md:430-432 aynı iddiayı tekrarlıyor ve 441-447'de bütün bir "Buyer Information Behavior" tablosu shortcode attribute'ları üzerinden kuruluyor: `| Some fields provided | Only missing fields are shown as inputs |`.
Kod ise nicepay-payment-gateway.php:464-466:
```
foreach ( array( 'amount', 'goods_name', 'goods_class', 'pay_method', 'currency', 'buyer_name', 'buyer_email', 'buyer_tel' ) as $key ) {
    $atts[ $key ] = isset( $saved[ $key ] ) ? $saved[ $key ] : '';
}
```
yani `shortcode_atts()` sonrası buyer alanları da kayıtlı konfigürasyondan (veya boş string'den) yeniden yazılıyor. Shortcode'da verilen değer hiçbir koşulda kullanılmıyor.
````

**Başarısızlık senaryosu**

Üye alanında `[nicepay_payment id="quick-payment" buyer_name="Kim Minsu" buyer_email="kim@example.com"]` kullanan bir site, dokümana göre alıcının yalnızca telefon girmesini bekler. Kod satır 464-466 buyer_name/buyer_email'i kayıtlı preset'in boş değerleriyle ezdiği için form üç alanı da boş gösterir; her ödemede alıcı tüm bilgileri yeniden yazmak zorunda kalır ve dönüşüm düşer. Sessiz hata; hiçbir uyarı üretilmez.

**Etki**

Dokümante edilen bir public API davranışı gerçekte yok. Kullanıcı README/USER-GUIDE'a göre sayfa bazında alıcı bilgisi ön-doldurma kurgular, hiçbir hata almaz, ama form her zaman boş alanlar gösterir. Aynı zamanda "Some fields provided → only missing fields are shown" senaryosu hiç uygulanamaz.

**Öneri**

Ya davranışı ya da dokümanı hizalayın. Doküman tarafı için: README.md:106-108 ve docs/USER-GUIDE.md:430-432 satırlarındaki üç buyer satırını `Ignored; buyer contact values always come from the saved configuration` olarak değiştirin; README.md:115 ve 117 ile docs/USER-GUIDE.md:439'daki sunucu-otoritesi cümlesine `buyer_name`, `buyer_email`, `buyer_tel` alanlarını da dahil edin; docs/USER-GUIDE.md:441-447 "Buyer Information Behavior" tablosunu "kayıtlı konfigürasyondaki alıcı alanları" üzerinden yeniden yazın (Shortcode Generator'daki alanlar). Kod tarafını tercih ederseniz, buyer alanlarını satır 464'teki zorlama listesinden çıkarıp yalnızca sunucuda `nicepay_validate_buyer_fields()` ile doğrulanacak şekilde geçirin — ama bu ticari olmayan alanlar için bilinçli bir ürün kararı olmalı.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: README.md:106-108/115 ve docs/USER-GUIDE.md:430-432/443-447, `buyer_name`/`buyer_email`/`buyer_tel` ön-doldurmasını SHORTCODE ATTRIBUTE'U olarak belgeliyor; oysa nicepay-payment-gateway.php:464-466 bu üç anahtarı `shortcode_atts()` sonrası koşulsuz olarak kayıtlı konfigürasyondan yeniden yazdığı için shortcode'da verilen değer hiçbir kod yolunda kullanılmıyor. Ön-doldurma özelliğinin KENDİSİ çalışıyor ve per-alan granülaritesi de gerçekten uygulanmış (templates/standalone-payment-form.php:125, :134, :143 — sadece boş olan alanlar input olarak render ediliyor, dolu olanlar hidden input olarak gidiyor, :169-171); yalnızca değerlerin KAYNAĞI dokümanda yanlış: kaynak, admin Shortcode Generator'daki Name/Phone/Email alanlarıyla kaydedilen sunucu tarafı kayıttır (admin/class-nicepay-admin.php:971-985, assets/js/nicepay-shortcode-admin.js:282-284) ve docs/DEVELOPER-GUIDE.md:238-240 bunu zaten doğru belgeliyor. Düzeltme: README.md:106-108 ve USER-GUIDE.md:430-432 satırlarını `Ignored; buyer prefill comes from the saved configuration` yapın, README.md:117 ile USER-GUIDE.md:439'daki sunucu-otoritesi cümlesine buyer_* alanlarını ekleyin ve USER-GUIDE.md:445-447 tablosundaki "in shortcode" ifadesini "in the saved configuration" ile değiştirin (tablo satırlarının DAVRANIŞ kısmı doğru, yeniden yazılmasına gerek yok).
- Gerekçe: Çekirdek iddia DOĞRU ve satır numaraları birebir tutuyor. `render_payment_shortcode()` içinde `shortcode_atts()` çağrısından (satır 459) hemen sonra gelen satır 464-466 döngüsü, `buyer_name`/`buyer_email`/`buyer_tel` dahil sekiz anahtarı `$saved`'dan (veya `''`'den) koşulsuz olarak yeniden yazıyor. `$saved` yalnızca `nicepay_get_saved_shortcode( $config_id )` çıktısı (satır 412), yani kayıtlı option kaydı; `$raw_atts` ile hiçbir noktada karışmıyor. Şablon da `$atts['buyer_name'|'buyer_email'|'buyer_tel']` okuduğu için (templates/standalone-payment-form.php:65-67) shortcode'da verilen buyer değeri hiçbir kod yolunda kullanılmıyor. İddiayı çürütecek bir guard/filter/üst katman doğrulaması bulamadım — `apply_filters` yok, ikinci bir shortcode handler yok. README.md:106-108 + 115 ve docs/USER-GUIDE.md:430-432 bu attribute'ları "Pre-fill" olarak tarif ediyor; aynı tabloda `amount`/`goods_name`/`currency`/`pay_method` satırları açıkça "Ignored as a commercial override" dediği için okuyucu buyer alanlarının BİLİNÇLİ olarak çalıştığı sonucunu çıkarır. README.md:117 ve USER-GUIDE.md:439'daki "sunucu otoritesi" cümleleri buyer alanlarını saymıyor. Dolayısıyla code-doc mismatch kesin.

İKİ DÜZELTME:

(1) Etki cümlesindeki "'Some fields provided → only missing fields are shown' senaryosu hiç uygulanamaz" iddiası YANLIŞ. Bu davranış şablonda gerçekten uygulanmış: templates/standalone-payment-form.php:125 (`if ( empty( $preset_name ) )`), :134 (`empty( $preset_email )`), :143 (`empty( $preset_tel )`) — her alan ayrı ayrı, sadece boşsa input olarak render ediliyor; dolu olanlar hidden input olarak gidiyor (:169-171). Yani USER-GUIDE.md:443-447 tablosundaki üç senaryonun ÜÇÜ DE gerçekten çalışıyor; tablonun tek hatası değerlerin KAYNAĞI (shortcode attribute değil, kayıtlı konfigürasyon). Bu, iddianın önerdiği "tabloyu tamamen yeniden yaz" tavsiyesini de gereksiz sertleştiriyor — tabloya yalnızca "in shortcode" ifadesini "in the saved configuration" ile değiştirmek yeterli. (Not: satır 69'daki `$show_buyer_fields = empty(...) || empty(...) || empty(...)` yalnızca kapsayıcı div'i gizliyor, per-alan mantığını bozmuyor.)

(2) Severity `high` fazla. Ortada ne veri kaybı, ne güvenlik açığı, ne başarısız ödeme var; ödeme akışı her koşulda tamamlanıyor. Dokümante edilen yeteneğin kendisi ÜRÜNDE MEVCUT, sadece başka bir yüzeyden: admin Shortcode Generator'daki Name/Phone/Email alanları (admin/class-nicepay-admin.php:975-985), ve o ekranın kendi yardım metni doğru — "If left empty, the buyer will fill these fields in the payment form. If provided, the fields will be pre-filled and hidden." (admin/class-nicepay-admin.php:971). docs/DEVELOPER-GUIDE.md:238-240 da kayıtlı yapıyı doğru belgeliyor. Yani yalnızca iki son-kullanıcı dokümanı (README, USER-GUIDE) bayat; kullanıcı hedefine yine ulaşabiliyor, sadece yanlış yerde arıyor. Sessiz-hata ve "public API davranışı yok" argümanı geçerli olduğu için `low` da değil → `medium`.

Ayrıca iddiadaki "üç dokümanda" ifadesi gevşek: `grep -rn "buyer_name" docs/ README.md` sonucuna göre yanlış anlatım yalnızca README.md ve docs/USER-GUIDE.md'de (toplam 2 doküman, 4 konum) var; analysis/ altındaki dosyalar zaten inceleme notu.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: Shortcode `buyer_name`/`buyer_email`/`buyer_tel` parametreleri README.md:106-108, README.md:115 ve docs/USER-GUIDE.md:430-432, 441-447'de "shortcode üzerinden ön-doldurma yapar" diye anlatılıyor; oysa nicepay-payment-gateway.php:464-466 bu üç alanı shortcode_atts() sonrası koşulsuz olarak kayıtlı konfigürasyondan (yoksa boş string'den) yeniden yazıyor. Bu, aynı PR'ın yarım kalmış doküman güncellemesidir: PR aynı tablodaki amount/goods_name/pay_method/currency satırlarını "Ignored as a commercial override" yapmış, "inline attributes override" notunu ve buyer ön-doldurmalı örneği silmiş, ama üç buyer satırını ve README.md:115'teki notu bırakmıştır.

ÖNEMLİ DÜZELTME 1: Ön-doldurma özelliği ölü DEĞİL — yalnızca kaynağı shortcode değil, Shortcode Generator'daki kayıtlı konfigürasyondur (admin/class-nicepay-admin.php:976/980/985; assets/js/nicepay-shortcode-admin.js:282-284). Generator'ın kendisi zaten sadece `[nicepay_payment id="..."]` üretiyor (aynı dosya:111-112), yani kod ve admin arayüzü tutarlı; tutarsız olan tek taraf elle yazılmış dokümanlardır.

ÖNEMLİ DÜZELTME 2: docs/USER-GUIDE.md:445-447 tablosunun üç satırı da davranışsal olarak DOĞRU şekilde uygulanmıştır — templates/standalone-payment-form.php:125-151 yalnızca EKSİK alanları input olarak çiziyor, dolu olanları satır 169-171'de hidden input'a taşıyor. Dolayısıyla "Some fields provided -> only missing fields are shown" senaryosu "hiç uygulanamaz" değildir; yanlış olan sadece tablonun satır 445'teki "in shortcode" kaynak ifadesidir. Düzeltme, tabloyu silmek değil, kaynağı "in the saved configuration" olarak değiştirmek olmalıdır.

Etki: güvenlik veya finansal bütünlük kusuru yok; kod dokümandan daha güvenli tarafta. Somut sonuç, dokümana güvenen entegratörün sayfa-bazlı ön-doldurma kurgulayıp sessizce başarısız olması (form üç alanı da boş gösterir), dönüşüm sürtünmesi ve zaman kaybıdır.
- Gerekçe: ÇEKİRDEK İDDİA DOĞRU VE ÜRETİLEBİLİR. Kod tarafını kendim okudum: `nicepay-payment-gateway.php:459` `shortcode_atts()` çağrısından sonra `464-466` satırlarındaki foreach döngüsü `buyer_name`/`buyer_email`/`buyer_tel` değerlerini KOŞULSUZ olarak `$saved`'den (yoksa boş string'den) yeniden yazıyor. `add_shortcode` yalnızca tek bir yerde kayıtlı (`nicepay-payment-gateway.php:112`), yani alternatif bir kod yolu yok. Shortcode'da verilen buyer değerinin kullanıldığı hiçbir dal mevcut değil. Doküman satır referansları da birebir doğru (README.md:106-108, 115, 117; docs/USER-GUIDE.md:430-432, 439, 441-447).

TEKRAR ÜRETİM ADIMLARI (somut, gerçekçi ön koşullarla):
1. Rol: `edit_posts` yetkisi olan herhangi bir editör/admin. Ek yetki gerekmiyor.
2. Bir sayfaya `[nicepay_payment id="quick-payment" buyer_name="Kim Minsu" buyer_email="kim@example.com"]` yazılır.
3. HTTPS + yapılandırılmış MID/merchant key ile sayfa görüntülenir (satır 417-424 kapıları geçilir).
4. Satır 412 `quick-payment` preset'ini yükler; bu preset'in buyer alanları `includes/nicepay-functions.php:1746-1748`'de boş string.
5. Satır 459 `$atts['buyer_name'] = 'Kim Minsu'` üretir; satır 464-466 bunu `''` ile ezer.
6. `templates/standalone-payment-form.php:69` -> `$show_buyer_fields = true`; satır 125-151 üç alanı da boş input olarak render eder.
Sonuç: dokümante edilen ön-doldurma hiç gerçekleşmez, hiçbir uyarı/hata üretilmez. Ulaşılamaz kod yolu değil, zamanlama koşulu yok, yarış durumu yok — deterministik.

PR DİFF'İ İDDİAYI GÜÇLENDİRİYOR: `git diff origin/main...development -- README.md` gösteriyor ki bu PR aynı tablodaki `amount`, `goods_name`, `pay_method`, `currency` satırlarını "Ignored as a commercial override" olarak GÜNCELLEMİŞ, altındaki "Any additional inline attributes override the saved values" notunu SİLMİŞ ve `buyer_name="John" buyer_email=...` içeren örneği KALDIRMIŞ; ama üç buyer tablo satırını ve README.md:115'teki ön-doldurma notunu context (değişmemiş) olarak bırakmış. Yani bu, PR'ın kendi içinde yarım kalmış bir doküman güncellemesi — klasik "stale leftover".

ANCAK İDDİANIN İKİ DETAYI YANLIŞ (bu yüzden partially-confirmed):

(a) "'Some fields provided -> only missing fields are shown' senaryosu HİÇ UYGULANAMAZ" iddiası KODLA ÇELİŞİYOR. `templates/standalone-payment-form.php:125-151` tam olarak alan-bazlı render yapıyor: `if (empty($preset_name))`, `if (empty($preset_email))`, `if (empty($preset_tel))` ayrı ayrı kontrol ediliyor; dolu olanlar satır 169-171'de hidden input olarak taşınıyor. Yani USER-GUIDE:445-447 tablosunun ÜÇ SATIRI DA davranışsal olarak DOĞRU; yanlış olan sadece sütun başlığındaki KAYNAK ("in shortcode" -> aslında "in the saved configuration"). İddia bunu "uygulanamaz" diye abartıyor.

(b) "Dokümante edilen bir public API davranışı GERÇEKTE YOK" ifadesi de fazla geniş. Ön-doldurma özelliği canlı ve çalışıyor, sadece kaynağı farklı: `admin/class-nicepay-admin.php:976`, `:980`, `:985` Shortcode Generator'da Buyer Name/Tel/Email input'ları var; `assets/js/nicepay-shortcode-admin.js:282-284` bunları kaydediyor; `:154` üçü de doluysa önizlemede alan bloğunu gizliyor. Ayrıca `assets/js/nicepay-shortcode-admin.js:111-112` `buildShortcode()` YALNIZCA `[nicepay_payment id="..."]` üretiyor — yani ürünün kendi üretici arayüzü kodla tutarlı, tutarsız olan tek taraf elle yazılmış dokümanlar.

SEVERITY DÜZELTMESİ (lens: sonuç ve istismar edilebilirlik): `high` ABARTILI. Bu bir güvenlik veya finansal bütünlük kusuru değil; tam tersine kod, dokümandan daha GÜVENLİ tarafta duruyor (buyer PII ve ticari alanlar sunucu otoritesinde). Düşük yetkili bir katkıcının shortcode üzerinden buyer PII enjekte etmesi de bu ezme sayesinde imkansız. Gerçek sonuç: yanlış beklenti + gereksiz form doldurma sürtünmesi + dönüşüm kaybı, ve dokümanı okuyan entegratörün zaman kaybı. Öte yandan `low` da değil: bu bir ödeme eklentisinin yayınlanan README'sindeki public API sözleşmesi, üç ayrı dokümanda tekrarlanıyor ve aynı PR'ın kendi güncellemesinin yarım kalmış parçası. Doğru seviye: `medium`.

---

### DOC-004 — ARCHITECTURE.md bu PR'ın mimarisini tanımıyor: yeni sınıflar, 70 sütunlu şema, refund tablosu, hook'lar ve opsiyonlar eksik; yetki modeli diğer dokümanlarla çelişiyor

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | stale-documentation |
| **Konum** | [docs/ARCHITECTURE.md:62](../../../docs/ARCHITECTURE.md#L62) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 doğrulandı, 1 kısmen |

**Sorun ve kanıt**

```markdown
1) Dizin yapısı (satır 62-90): `class-nicepay-retention.php`, `class-nicepay-privacy.php`, `class-nicepay-blocks-integration.php`, `assets/js/nicepay-blocks.js`, `assets/js/nicepay-shortcode-admin.js`, `readme.txt`, `tests/` ve `.github/` hiç yok — hepsi bu PR'da eklendi.
2) Class diyagramı (satır 94-171): yeni 7 sınıfın HİÇBİRİ yok; yalnızca NicePay_Payment_Gateway, NicePay_API, WC_Gateway_NicePay, NicePay_Return_Handler, NicePay_Admin, NicePay_Transactions çiziliyor.
3) ERD (satır 305-340): 27 sütun listeliyor. Gerçek tablo 70 sütun (includes/class-nicepay-transaction-schema.php:42-115; `flow`, `source_ref`, `active_attempt_key`, `config_fingerprint`, `currency`, `captured_amount`, `refunded_amount`, `remaining_amount`, `approval_state`, `reconciliation_*`, `receipt_token_hash`, `cancel_*`, `net_cancel_*`, `otid`, `vbank_*_at` hepsi eksik). `{prefix}nicepay_refund_attempts` tablosu (schema:145-174) "Database Schema" bölümünde hiç geçmiyor.
4) Hook tablosu (satır 410-427): `woocommerce_blocks_loaded`, `before_woocommerce_init`, `wp_initialize_site`, `wp_ajax_nicepay_refresh_nonce`, `admin_post_nicepay_export_transactions`, `nicepay_expire_pending_transactions` cron'u, `nicepay_apply_financial_retention` cron'u, `update_option_nicepay_retention_settings`, `woocommerce_order_status_cancelled`, privacy exporter/eraser filtreleri yok.
5) Opsiyon tablosu (satır 443-459): kritik güvenlik anahtarı `nicepay_standalone_enabled` yok; `nicepay_retention_lock` yok; buna karşılık `nicepay_db_version | string | Database schema version` diye belgeleniyor — bu opsiyon kodda YALNIZCA yazılıyor, hiç okunmuyor (`grep -rn nicepay_db_version --include=*.php` yalnız nicepay-payment-gateway.php:283 `add_option(...)` satırını buluyor). Gerçek şema sürümü `nicepay_transactions_schema_version`.
6) Güvenlik katmanı tablosu satır 401: `| **Authorization** | WordPress manage_options capability for admin actions |` — kod includes/nicepay-functions.php:540 `apply_filters( 'nicepay_manage_transactions_capability', 'manage_woocommerce' )` kullanıyor; README.md:190 ve CONTRIBUTING.md:199 doğru davranışı anlatıyor. ARCHITECTURE tek başına çelişiyor.
```

**Başarısızlık senaryosu**

Bir katkıcı yeni bir yönetim ekranı ekler ve ARCHITECTURE.md:401'e dayanarak `current_user_can( 'manage_options' )` yazar. Ekran, ürünün asıl modeli olan `nicepay_manage_transactions_capability` (varsayılan `manage_woocommerce`) ile tutarsız olur: mağaza yöneticisi (shop_manager) diğer NicePay işlem ekranlarını görürken bu yeni ekranı göremez; filtreyle yetki daraltan bir site ise bu ekranda daraltmanın uygulanmadığını fark eder.

**Etki**

Yeni katkıcı veya güvenlik denetçisi mimariyi bu dokümandan öğrenmeye çalışırsa; sunucu-otoriteli teklif çözümleyici, inbound doğrulayıcı, atomik claim, saklama ve gizlilik altyapısının varlığından haberdar olmaz, veri modelinin yarısını görmez ve yetki modelini yanlış bilir. "Architecture Overview" başlığını taşıyan dokümanın PR'ın asıl konusu olan katmanları hiç anlatmaması, README.md:182'deki "System design, class relationships, and data flow" vaadini karşılamıyor.

**Öneri**

ARCHITECTURE.md'yi bu PR'ın gerçek hâline göre yeniden üretin: (a) dizin ağacına 7 yeni sınıfı, blocks/shortcode-admin JS'lerini ve readme.txt'yi ekleyin; (b) class diyagramına NicePay_Installer, NicePay_Transaction_Schema, NicePay_Inbound_Validator, NicePay_Offer_Resolver, NicePay_Retention, NicePay_Privacy, NicePay_Blocks_Integration'ı ve bunların gateway/return-handler ile ilişkilerini ekleyin; (c) ERD'yi sütun gruplarına bölün (kimlik/bağlama, para, durum, mutabakat, iptal, makbuz) ve `nicepay_refund_attempts` için ikinci bir varlık ile 1-N ilişkisini çizin — 70 sütunun tamamını listelemek yerine grup + "tek kaynak: class-nicepay-transaction-schema.php" referansı verin; (d) hook tablosunu nicepay-payment-gateway.php:93-127'ye göre tamamlayın; (e) opsiyon tablosuna `nicepay_standalone_enabled` ve `nicepay_retention_lock` ekleyin, `nicepay_db_version`'ı ya kodda kaldırın ya da tabloda "legacy, kullanılmıyor" diye işaretleyin; (f) satır 401'i `nicepay_manage_transactions_capability` (varsayılan `manage_woocommerce`) + ayarlar için `manage_options` olarak düzeltin.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: Her alt iddiayı dosyaları açarak tek tek doğruladım; hepsi tuttu ve verilen satır numaraları dosyanın şu anki hâliyle birebir eşleşiyor. İddiayı çürütecek bir "başka yerde anlatılıyor" savunması aradım ve bulamadım: ARCHITECTURE.md içinde `NicePay_Installer`, `NicePay_Transaction_Schema`, `NicePay_Inbound_Validator`, `NicePay_Offer_Resolver`, `NicePay_Blocks_Integration` sınıf adları HİÇ geçmiyor (grep 0 sonuç); `NicePay_Privacy` ve `NicePay_Retention` yalnızca dosyanın en sonundaki 461/463 numaralı düz metin paragraflarında geçiyor — ne dizin ağacında ne de sınıf diyagramında. `nicepay_refund_attempts` kelimesi dokümanın tamamında bir kez bile geçmiyor.

Dikkat çeken tek nüans, iddianın dizin ağacı için haksız olmadığıdır: ağaç bu PR'da güncellenmiş ve 4 yeni sınıfı (offer-resolver, inbound-validator, installer, transaction-schema, satır 71-74) gerçekten içeriyor — ancak iddia zaten bunları eksik saymıyor, yalnızca retention/privacy/blocks-integration + iki JS + readme.txt + tests/ + .github/ eksikliğini öne sürüyor ve bunların hepsi gerçekten eksik. Yani doküman "kısmen güncellenmiş, yarısı bırakılmış" durumda ki bu iddiayı zayıflatmıyor, aksine tutarsızlığı doğruluyor.

Yetki çelişkisi (en ağır alt madde) tam olarak iddia edildiği gibi: ARCHITECTURE.md:401 tek başına `manage_options` diyor, kod ise ikili bir model kullanıyor — işlem ekranları `nicepay_manage_transactions_capability()` (varsayılan `manage_woocommerce`), ayarlar sayfası `manage_options`. README.md:190 ve CONTRIBUTING.md:199 bu ikili modeli doğru anlatıyor; çelişen tek doküman ARCHITECTURE.md. Bu, öneri (f)'yi de doğruluyor.

`nicepay_db_version` iddiası da doğrulandı: tüm PHP ağacında tek bir satırda, sadece yazılarak geçiyor (`add_option`), hiçbir yerde `get_option` ile okunmuyor — buna rağmen opsiyon tablosunda "Database schema version" olarak belgeleniyor; gerçek migratör anahtarı `NicePay_Installer::VERSION_OPTION = 'nicepay_transactions_schema_version'`.

Tek gerçek detay hatası: iddia ERD'nin "27 sütun" listelediğini söylüyor; saydığımda 29 sütun. Gerçek şema 70 sütun (grep ile doğrulandı), dolayısıyla kıyas ve bulgunun özü değişmiyor — bu yüzden partially-confirmed'e düşürmedim, ama not düştüm. `severity: high` savunulabilir: salt eskimişlik olsa medium olurdu, fakat 401. satırdaki yetki ifadesi aktif olarak YANLIŞ ve iki başka dokümanla çelişiyor; bu, "Security Architecture" başlığı altında yayınlanan yanlış bir güvenlik ifadesidir.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: ARCHITECTURE.md bu PR'da kısmen güncellendi (+42/-21; Privacy, Retention ve approval-claim yaşam döngüsü prose/state-diagram olarak anlatılıyor; dizin ağacına offer-resolver, inbound-validator, installer, transaction-schema eklendi) ancak yapısal bölümleri kodun gerisinde kaldı: (1) class diyagramı (94-171) 7 yeni sınıfın hiçbirini içermiyor — bu sınıf adları dokümanın tamamında hiç geçmiyor; (2) ERD (305-340) 29 sütun gösteriyor, gerçek tablo 70 sütun ve ikinci tablo `nicepay_refund_attempts` hiç belgelenmemiş; (3) dizin ağacında retention/privacy/blocks-integration sınıfları, nicepay-blocks.js, nicepay-shortcode-admin.js, readme.txt, tests/ ve .github/ eksik (7 sınıfın 4'ü ZATEN listelenmiş durumda); (4) hook tablosu (410-427) blocks, multisite, cron, retention, privacy ve export hook'larını kaçırıyor; (5) opsiyon tablosunda standalone akışının güvenlik kapısı `nicepay_standalone_enabled` ve `nicepay_retention_lock` yok, buna karşılık kodda yalnızca yazılıp hiç okunmayan ölü `nicepay_db_version` "Database schema version" diye belgeleniyor; (6) satır 401 yetki modelinin sadece yarısını yazıyor — `manage_options` ayarlar/shortcode aksiyonları için gerçekten kullanılıyor (admin/class-nicepay-admin.php:43, nicepay-payment-gateway.php:258/656/771), ama operasyonel işlem ekranları filtrelenebilir `nicepay_manage_transactions_capability` (varsayılan `manage_woocommerce`) kullanıyor; bu yüzden satır 401 "çelişkili" değil, EKSİK ve yanıltıcı. Etki tamamen doküman/bakım düzeyindedir: çalışma zamanı davranışı etkilenmez, güvenlik açığı doğurmaz (yanlış yön daha DAR yetkiye, yani aşırı-kısıtlamaya işaret eder) ve doğru yetki modeli README.md:190 ile CONTRIBUTING.md:199'da zaten yazılıdır.
- Gerekçe: Çekirdek iddia doğru ve ben doğruladım: ARCHITECTURE.md bu PR'da eklenen mimarinin büyük kısmını (class diyagramı, ERD, hook tablosu, opsiyon tablosu) yansıtmıyor ve satır 401'deki yetki satırı ürünün gerçek operasyonel yetki modeliyle uyuşmuyor. Ancak İDDİANIN BİRKAÇ DETAYI ABARTILI ve severity `high` fazla yüksek.

DOĞRULANANLAR (hepsini koddan okudum):
- ERD 29 (iddia "27" diyor) sütun listeliyor; gerçek tablo 70 sütun (`awk 'NR>=42&&NR<=118' includes/class-nicepay-transaction-schema.php | grep -c "=>"` -> 70). `flow`, `source_ref`, `active_attempt_key`, `config_fingerprint`, `currency`, `captured_amount`, `refunded_amount`, `remaining_amount`, `approval_state`, `reconciliation_*`, `receipt_token_hash`, `cancel_*`, `net_cancel_*`, `otid`, `vbank_*_at` gerçekten yok.
- `{prefix}nicepay_refund_attempts` tablosu ("Database Schema" bölümü 299-359) hiç geçmiyor; `grep -n "refund_attempts" docs/ARCHITECTURE.md` -> 0 sonuç. Oysa `refund_columns()`/`refund_indexes()` gerçek bir ikinci tablo tanımlıyor.
- Class diyagramı (94-171) yalnız 6 eski sınıfı çiziyor; `grep -n "Offer_Resolver\|Inbound_Validator\|Installer\|Transaction_Schema\|Blocks_Integration" docs/ARCHITECTURE.md` -> 0 sonuç. Yani 7 yeni sınıfın hiçbiri diyagramda yok, sınıf adı olarak dokümanda hiç geçmiyor.
- Hook tablosu (410-427) eksik: `before_woocommerce_init`, `woocommerce_blocks_loaded`, `wp_initialize_site`, `wp_ajax_nicepay_refresh_nonce`, `nicepay_expire_pending_transactions`, retention cron, `update_option_nicepay_retention_settings`, `woocommerce_order_status_cancelled`, privacy exporter/eraser filtreleri, `admin_post_nicepay_export_transactions`, `woocommerce_admin_order_data_after_order_details` — hepsi kodda kayıtlı, tabloda yok.
- Opsiyon tablosunda `nicepay_standalone_enabled` yok — bu opsiyon standalone akışının GÜVENLİK KAPISI (nicepay-payment-gateway.php:395, 533, 635 ve includes/nicepay-functions.php:504 hepsi buna bakıp erken çıkıyor). `nicepay_retention_lock` (includes/class-nicepay-retention.php:20) da yok.
- `nicepay_db_version` gerçekten ölü: tüm repoda tek referans `nicepay-payment-gateway.php:283 add_option( 'nicepay_db_version', NICEPAY_VERSION );` — hiçbir `get_option`/`update_option` yok. Yine de tabloda "Database schema version" diye belgeleniyor (satır 457), gerçek migrator sürümü ise satır 459'daki `nicepay_transactions_schema_version`.
- Satır 401 vs kod: `includes/nicepay-functions.php:540` `apply_filters( 'nicepay_manage_transactions_capability', 'manage_woocommerce' )`, admin menüleri `admin/class-nicepay-admin.php:23,34` bunu kullanıyor. README.md:190 ve CONTRIBUTING.md:199 doğru modeli anlatıyor.

DÜZELTMELER (iddianın yanlış/abartılı kısımları):
1. "Dizin ağacında yeni sınıflar hiç yok" YANLIŞ. docs/ARCHITECTURE.md:71-74 zaten `class-nicepay-offer-resolver.php`, `class-nicepay-inbound-validator.php`, `class-nicepay-installer.php`, `class-nicepay-transaction-schema.php` satırlarını içeriyor. Gerçekten eksik olanlar sadece 3'ü: retention, privacy, blocks-integration (+ nicepay-blocks.js, nicepay-shortcode-admin.js, readme.txt, tests/, .github/). Önerinin (b) maddesi de "7 yeni sınıfı dizin ağacına ekleyin" derken hatalı.
2. "Katkıcı bu katmanların varlığından haberdar olmaz" ABARTILI. Satır 461-463 `NicePay_Privacy` ve `NicePay_Retention`'ı prose olarak ayrıntılı anlatıyor; satır 344-359 "Atomic approval claim", `approving`, `needs_reconciliation` yaşam döngüsünü çiziyor; satır 319 auth_token saklama politikasını anlatıyor. Doküman bu PR'da 42 satır güncellenmiş (git diff --numstat: `42 21 docs/ARCHITECTURE.md`) — tamamen stale değil, KISMEN güncellenmiş ve diyagramlar geride kalmış.
3. Satır 401 "çelişiyor" değil, "EKSİK/yanıltıcı". `manage_options` kodda gerçekten kullanılıyor: `admin/class-nicepay-admin.php:43` (ayarlar alt menüsü), `:347`, `nicepay-payment-gateway.php:258, 656, 771` (shortcode kaydet/sil AJAX ve aktivasyon). Ayrıca `includes/nicepay-functions.php:485` `current_user_can( 'manage_options' ) || current_user_can( nicepay_manage_transactions_capability() )` şeklinde ikisini OR'luyor. Yani doküman yanlış bir yetki uyduruyor değil; ikili modelin sadece bir yarısını yazıyor.

SEVERITY (sonuç/istismar merceği): `high` fazla. Bu bulgunun ÇALIŞMA ZAMANI ETKİSİ SIFIR — ARCHITECTURE.md hiçbir kod yolundan okunmuyor, kullanıcıya sunulan bir yüzey değil. failure_scenario doğrudan üretilebilir bir istismar değil; gelecekte bir katkıcının doküman satırına bakıp `manage_options` yazmasını gerektiren dolaylı bir zincir. Üstelik sonucu güvenlik açığı DEĞİL: `manage_options`, `manage_woocommerce`'tan DAHA DAR bir yetkidir, dolayısıyla hata yönünde aşırı-kısıtlama (shop_manager ekranı göremez) oluşur, ayrıcalık yükselmesi olmaz. Ek olarak aynı bilgi CONTRIBUTING.md:199 ve README.md:190'da doğru yazılı, yani katkıcı için tek kaynak değil. Gerçek maliyet: onboarding sürtünmesi + doküman bakım borcu + yeni veri modelinin yarısının hiçbir yerde şematik karşılığının olmaması. Bu `medium`.

---

### DOC-005 — Public filtrelerin 5/7'si hiçbir dokümanda yok; "Hooks, filters" vaadi veren Developer Guide yalnızca WooCommerce çekirdek hook'larını anlatıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | missing-api-documentation |
| **Konum** | [docs/DEVELOPER-GUIDE.md:62](../../../docs/DEVELOPER-GUIDE.md#L62) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 kısmen |

**Sorun ve kanıt**

```markdown
Kodda 7 `apply_filters` uzantı noktası var:
- includes/class-nicepay-api.php:211 `nicepay_http_timeout`
- includes/class-nicepay-api.php:245 `nicepay_http_connect_timeout`
- includes/nicepay-functions.php:302 `nicepay_public_rate_limit_identity`
- includes/nicepay-functions.php:303 `nicepay_public_rate_limit_max_requests`
- includes/nicepay-functions.php:304 `nicepay_public_rate_limit_window`
- includes/nicepay-functions.php:540 `nicepay_manage_transactions_capability`
- includes/nicepay-functions.php:583 `nicepay_goods_cl`
Doküman taraması (`grep -rln <filtre> README.md readme.txt CHANGELOG.md docs/*.md`): yalnızca `nicepay_http_timeout` ve `nicepay_http_connect_timeout` docs/API-REFERENCE.md:409'da geçiyor. Diğer 5'i için hiçbir eşleşme yok. docs/DEVELOPER-GUIDE.md:62-103 "Adding Custom Logic with Hooks" bölümü yalnızca `woocommerce_payment_complete` ve `woocommerce_payment_complete_order_status` örneklerini veriyor; satır 91 ise "The plugin does not currently publish a transaction-saved action" diyerek uzantı noktası olmadığı izlenimini pekiştiriyor. README.md:183 bu dokümanı "Hooks, filters, customization, and extending the plugin" diye tanıtıyor. Ayrıca iki cron hook'u (`nicepay_expire_pending_transactions`, `NicePay_Retention::CRON_HOOK = 'nicepay_apply_financial_retention'`) da hiçbir dokümanda geçmiyor.
```

**Başarısızlık senaryosu**

Çok satıcılı bir sitede NicePay işlem ekranını yalnızca finans ekibine açmak isteyen geliştirici, DEVELOPER-GUIDE'da bir yetki filtresi bulamaz; `manage_woocommerce` yetkisini WordPress rolünden söker ve bunun tüm WooCommerce yönetimini bozmasına yol açar. Oysa tek satırlık `add_filter( 'nicepay_manage_transactions_capability', fn() => 'nicepay_finance' )` yeterliydi.

**Etki**

Ürünün en değerli uzantı noktaları — yetki daraltma, rate-limit ayarlama, mobil ödeme GoodsCl seçimi — kullanıcıya görünmez. Entegratörler ya kodu okumak zorunda kalır ya da desteklenmeyen yollara (dosya düzenleme, çekirdek gettext hack'i) sapar; sonraki sürümde filtreler değişirse hangi sözleşmenin public olduğu da belirsiz kalır.

**Öneri**

docs/DEVELOPER-GUIDE.md'ye "Filters published by this plugin" başlıklı bir tablo ekleyin: filtre adı, imza (parametreler ve tipleri), varsayılan, geçerli aralık/doğrulama (örn. http timeout 1-120 ile sınırlanıyor, goods_cl yalnız '0'/'1' kabul ediliyor), ve kısa bir örnek. Cron hook'larını da (`nicepay_expire_pending_transactions`, `nicepay_apply_financial_retention`) zamanlama ve elle tetikleme notuyla belgeleyin. Örnek:
```php
// Mobil ödemede ürün tipini kendi mantığınızla belirleyin ('0' dijital, '1' fiziksel).
add_filter( 'nicepay_goods_cl', function ( $goods_cl, $order ) {
    return $order->has_downloadable_item() ? '0' : '1';
}, 10, 2 );
```
Ayrıca API-REFERENCE.md:409'daki iki timeout filtresine bu tablodan çapraz referans verin.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Kodun yayımladığı 7 filtreden yalnızca 2'si (`nicepay_http_timeout`, `nicepay_http_connect_timeout`) dokümante edilmiş — o da docs/API-REFERENCE.md:409'da imza/varsayılan/sınır belirtilmeyen tek bir cümleyle. `nicepay_manage_transactions_capability` yalnızca CONTRIBUTING.md:199'da (katkıda bulunan rehberi; README'nin Documentation bölümünden entegratöre işaret edilmez, örnek verilmez) tek satır olarak anılıyor. Kalan 4 filtre (`nicepay_public_rate_limit_identity`, `nicepay_public_rate_limit_max_requests`, `nicepay_public_rate_limit_window`, `nicepay_goods_cl`) ve iki cron hook'u (`nicepay_expire_pending_transactions`, `nicepay_apply_financial_retention`) hiçbir yerde belgelenmiş değil (docs/analysis/* iç inceleme notlarıdır, yayımlanmış sözleşme değil). README.md:183 docs/DEVELOPER-GUIDE.md'yi "Hooks, filters" kaynağı diye tanıtsa da o dosyanın 508 satırında eklentinin kendi filtrelerinden hiçbiri geçmiyor; :91 ise açıkça uzantı noktası olmadığı izlenimini pekiştiriyor. Öneri (DEVELOPER-GUIDE'a filtre + cron tablosu) aynen geçerli.
- Gerekçe: İddianın çekirdeği doğrulandı: kodda tam olarak 7 `apply_filters` uzantı noktası var, verilen dosya:satır referanslarının hepsi dosyaların şu anki haliyle birebir eşleşiyor (nicepay-functions.php:302-304, :540, :583; class-nicepay-api.php:211, :245). Kullanıcıya dönük dokümanlarda (README.md, readme.txt, CHANGELOG.md, docs/*.md) yalnızca `nicepay_http_timeout` ve `nicepay_http_connect_timeout` geçiyor — ikisi de docs/API-REFERENCE.md:409'da, tek cümle içinde, imza/varsayılan/sınır bilgisi olmadan. DEVELOPER-GUIDE.md:62'deki "Adding Custom Logic with Hooks" bölümü (62-103) gerçekten yalnızca WooCommerce çekirdek hook'larını (`woocommerce_payment_complete`, `woocommerce_payment_complete_order_status`) örnekliyor; :91 "The plugin does not currently publish a transaction-saved action" diyor; 508 satırlık dosyanın tamamında eklentinin kendi filtrelerinden hiçbiri geçmiyor (grep: sadece :96 woo filtresi, :358 gettext hack'i, :394/:398 `option_*` filtreleri — yani iddianın "desteklenmeyen yollara/gettext hack'ine sapar" gözlemi de dokümanın kendi içeriğiyle uyumlu). README.md:183 bu dosyayı "Hooks, filters, customization, and extending the plugin" diye tanıtıyor. İki cron hook'u da (`nicepay_expire_pending_transactions` — nicepay-payment-gateway.php:104/166/167; `NicePay_Retention::CRON_HOOK = 'nicepay_apply_financial_retention'` — class-nicepay-retention.php:21) hiçbir dokümanda geçmiyor.

Tek düzeltme: iddia "Diğer 5'i için hiçbir eşleşme yok" diyor, ancak bu yalnızca iddianın taradığı dosya kümesi (README/readme.txt/CHANGELOG/docs/*.md) için geçerli. Repoda daha geniş bir grep, `nicepay_manage_transactions_capability` için CONTRIBUTING.md:199'da tek satırlık bir bahis buluyor: "Operational payment screens use the filterable `nicepay_manage_transactions_capability` (default `manage_woocommerce`)". Bu, filtrenin var ve filtrelenebilir olduğunu kayda geçiriyor — dolayısıyla "belgesiz filtre" sayısı 5 değil, kullanıcıya/entegratöre dönük dokümantasyonda 5 ama repo genelinde tam belgesiz olan 4 (+3 kısmi). Ayrıca CONTRIBUTING.md bir katkıda bulunan rehberidir, README'nin "Documentation" bölümünden entegratöre işaret edilmez, örnek/imza vermez; docs/analysis/* dosyaları ise ürün dokümanı değil, iç inceleme notlarıdır (02-protocol-conformance.md:694/702 ve 10-documentation.md:1104 `nicepay_goods_cl`'i "öneri" olarak anıyor, yayımlanmış sözleşme olarak değil). Bu yüzden başarısızlık senaryosunun yönü geçerli kalıyor ama tam olarak `nicepay_manage_transactions_capability` örneğiyle en zayıf noktasından verilmiş: dikkatli bir geliştirici CONTRIBUTING.md'de bunu bulabilir. Severity medium olarak korunmalı.

---

### DOC-006 — Kullanıcıya görünen özellikler belgesiz: System Report sekmesi, hazırlık paneli, CSV dışa aktarma, standalone makbuz linki ve makbuz e-postası

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | missing-user-documentation |
| **Konum** | [docs/USER-GUIDE.md:109](../../../docs/USER-GUIDE.md#L109) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

```markdown
docs/USER-GUIDE.md:109: "**Settings** — 5 configuration tabs". Gerçekte 6 sekme var: admin/class-nicepay-admin.php:366-390'da General, API Credentials, Payment Methods, Shortcodes, Shortcode Generator ve **System Report** (satır 387-390, `tab=system-report`, render_system_report_tab() satır 409-411). README.md:150-156 tab tablosu da System Report'u içermiyor.
Belgelenmemiş diğer görünür özellikler:
- Hazırlık paneli: admin/class-nicepay-admin.php:425 `render_readiness_panel()` her ayar sayfasının üstünde mod/kimlik/HTTPS/cron durumunu gösteriyor; `grep -rn "readiness" docs/USER-GUIDE.md README.md` yalnızca CONFIGURATION.md:242'de ilgisiz bir kullanım buluyor.
- CSV dışa aktarma: admin/class-nicepay-transactions.php:25 `admin_post_nicepay_export_transactions`, satır 690 "Export filtered CSV" butonu ve satır 696 satır limiti mesajı. README.md:158-164 ve docs/USER-GUIDE.md:238-285 "Transactions" bölümlerinin ikisinde de dışa aktarma yok — ama docs/CONFIGURATION.md:71 kullanıcıya "Export the relevant transaction CSV" diye talimat veriyor.
- Standalone makbuz: includes/nicepay-functions.php:1052-1081 kalıcı makbuz token'ı üretiyor (`?nicepay_receipt=<token>`) ve 1152-1173 `nicepay_send_standalone_receipt_email()` alıcıya e-posta gönderiyor. docs/USER-GUIDE.md:544-551 standalone başarı akışını anlatırken yalnızca "Return to Home" butonundan söz ediyor; makbuz linki/e-postası hiçbir kullanıcı dokümanında geçmiyor.
```

**Başarısızlık senaryosu**

Yönetici, CONFIGURATION.md:71'deki "Export the relevant transaction CSV" adımını uygulamak için USER-GUIDE'ın Transactions bölümüne bakar, dışa aktarmadan söz edilmediği için özelliğin olmadığını sanır ve saklama süresini yedeksiz açar; süre dolduğunda silinen defter satırları nedeniyle sonraki iadeler imkânsız hale gelir. Ayrı bir senaryoda satıcı, standalone ödeme sonrası alıcılara otomatik makbuz e-postası gittiğini bilmediği için gizlilik bildirimini eksik hazırlar.

**Etki**

Satıcı, elindeki teşhis ve uyum araçlarını (System Report, hazırlık paneli, CSV export) bilmiyor; destek talepleri gereksiz yere artıyor. Daha kötüsü, standalone alıcılara otomatik e-posta gidiyor ve kalıcı bir bearer-token makbuz URL'i üretiliyor — satıcının gizlilik bildirimi ve e-posta teslimat yapılandırması açısından bilmesi gereken bir davranış hiçbir yerde yazılı değil.

**Öneri**

docs/USER-GUIDE.md:109'u "6 configuration tabs" yapın ve Admin Panel bölümüne "System Report" alt başlığı ekleyin (ne gösterdiği, sırların maskelendiği, destek talebine nasıl eklenebileceği). "Transactions" bölümüne "Exporting to CSV" alt başlığı ekleyin (filtrelerin dışa aktarmaya uygulandığı, `CSV_MAX_ROWS` satır limiti, formül enjeksiyonuna karşı korunduğu). Ayar sayfası anlatımına hazırlık panelini ekleyin. "Standalone Payments" bölümüne "Receipts" alt başlığı ekleyin: kalıcı makbuz URL'i (bearer token, paylaşan herkes görür), makbuz e-postasının ne zaman ve hangi içerikle gittiği, ve WordPress eraser'ın makbuz erişimini kaldırdığı. README.md:150-164 tablolarını da aynı şekilde güncelleyin.

---

### DOC-007 — İşlem durumu taksonomisi hiçbir dokümanda tam değil: `abandoned`, `expired`, `cancelled`, `waiting` durumları belgesiz

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | incomplete-reference |
| **Konum** | [docs/DEVELOPER-GUIDE.md:210](../../../docs/DEVELOPER-GUIDE.md#L210) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

```markdown
Kod 11 durum etiketi tanımlıyor (includes/nicepay-functions.php:1671-1686): pending, paid, failed, cancelled, refunded, waiting, approving, partially_refunded, needs_reconciliation, abandoned, expired. Bunlardan `abandoned` ve `expired` aktif olarak yazılıyor: nicepay-functions.php:675-676 (rakip denemelerin abandoned yapılması), :933 (`nicepay_abandon_pending_transactions`), :959 (`nicepay_expire_pending_transactions` cron'u).
Dokümanlar:
- docs/DEVELOPER-GUIDE.md:210-221 "Transaction Status Values" tablosu 7 durum: pending, paid, failed, approving, partially_refunded, refunded, needs_reconciliation.
- docs/ARCHITECTURE.md:316 ERD status açıklaması aynı 7 durumu sayıyor; :344-359 durum makinesi diyagramında abandoned/expired/cancelled/waiting geçişleri yok.
- docs/USER-GUIDE.md:269-276 "Status Badges" tablosunda 6 rozet var; `approving` bile yok. Satır 248 filtreleri "and historical states" diye geçiştiriyor.
```

**Başarısızlık senaryosu**

Müşteri ödeme penceresini kapatır, cron `nicepay_expire_pending_transactions` satırı `expired` yapar. Yönetici Transactions ekranında "Expired" görür, USER-GUIDE'ın rozet tablosunda bulamaz, NICEPAY konsolunda bir kayıt olup olmadığını bilemez ve destek talebi açar; ya da daha kötüsü, `abandoned` satırını "kayıp ödeme" sanıp müşteriye elle iade dener.

**Etki**

Yönetici Transactions ekranında "Abandoned" veya "Expired" rozeti gördüğünde hiçbir dokümanda karşılığını bulamaz; bunun para kaybı mı, normal temizlik mi olduğunu ayırt edemez. Geliştirici tarafında da durum makinesi eksik belgelendiği için özel raporlama/entegrasyon kodu bu durumları kaçırır.

**Öneri**

Üç tabloyu tek bir kaynaktan üretin. docs/DEVELOPER-GUIDE.md:210-221 tablosunu 11 duruma tamamlayın ve her biri için "kim yazar" (claim, cron, abandon, iade akışı) sütunu ekleyin. docs/ARCHITECTURE.md:344-359 state diyagramına `pending --> abandoned : Sibling attempt claimed / new attempt started`, `pending --> expired : Expiry cron`, `paid --> cancelled` (uygunsa) geçişlerini ekleyin. docs/USER-GUIDE.md:269-276 rozet tablosuna Approving, Abandoned ve Expired satırlarını, her biri için "ne yapmalı" sütunuyla (örn. Abandoned/Expired: aksiyon gerekmez, müşteri yeni deneme başlatabilir) ekleyin. `waiting` ve `cancelled` kullanılmıyorsa etiket listesinden kaldırın veya "yalnız tarihsel kayıtlar" diye işaretleyin.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: Her iddia edilen konumu açıp okudum; iddiayı çürütecek bir doküman/koruma bulamadım.

1) Kod tarafı: `nicepay_get_status_label()` gerçekten 11 durum etiketi tanımlıyor (includes/nicepay-functions.php:1672-1683 — fonksiyon başlığı 1671, dizi 1672'de başlıyor; iddiadaki 1671-1686 aralığı bir satır kaymayla esasen doğru).

2) `abandoned` ve `expired` aktif olarak DB'ye yazılıyor: :675-676 (rakip deneme abandoned), :933 (`abandoned` toplu geçiş), :959 (`expired` geçişi). Yani bunlar "ölü etiket" değil, üretimde oluşabilen durumlar.

3) Bu durumlar yöneticiye GÖRÜNÜR: admin/class-nicepay-transactions.php:277 filtre dropdown'ı 11 durumun tamamını döndürüyor ve :914 satırında rozet `nicepay_get_status_label( $item->status )` ile basılıyor. Yani "Abandoned"/"Expired" rozeti ve filtre seçeneği ekranda gerçekten çıkar.

4) Dokümanlar eksik: DEVELOPER-GUIDE.md:210-221 tablosu tam olarak 7 durum sayıyor; ARCHITECTURE.md:316 ERD status açıklaması aynı 7 durumu listeliyor; ARCHITECTURE.md:343-359 state diyagramında abandoned/expired/cancelled/waiting hiçbir geçiş yok; USER-GUIDE.md:269-276 rozet tablosunda 6 rozet var ve `approving` gerçekten yok (satır 248 "and historical states" ile geçiştiriyor). API-REFERENCE.md'de de durum taksonomisi yok (yalnızca :409'da `needs_reconciliation` geçiyor).

5) Çürütücü kanıt aradım: `grep -rn "abandoned|expired" docs/` yalnızca docs/analysis/* altındaki ESKİ denetim raporlarında eşleşiyor (ör. 03-payment-flow...:1134, 1486) — bunlar kullanıcı/geliştirici dokümanı değil, PR öncesi bulgu raporları ve zaten "bu durumları EKLEYİN" önerisi olarak yazılmışlar. Yani resmi üç dokümanda karşılığı yok.

6) İddianın "waiting/cancelled kullanılmıyorsa kaldırın" kısmı da doğrulandı: includes/ admin/ templates/ altında hiçbir yerde `status`'a `'waiting'` veya `'cancelled'` YAZAN kod yok; bu iki değer yalnızca etiket dizisinde (1676, 1678), filtre listesinde (transactions.php:277) ve retention temizlik listesinde (class-nicepay-retention.php:308) okunuyor. Dolayısıyla hem eksik belgeleme hem de ölü/tarihsel etiket sorunu gerçek.

Severity `medium` uygun: para kaybı yok, ama merchant'ın tek mutabakat yüzeyinde belgesiz durum rozetleri ve retention silme davranışıyla (:308 abandoned/expired satırları temizleme kapsamında) birleşince yanlış yorum riski somut.

---

### DOC-008 — Loglama dokümantasyonu yanlış: WooCommerce yokken debug/info kayıtları hiçbir yere yazılmıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | code-doc-mismatch |
| **Konum** | [docs/DEVELOPER-GUIDE.md:416](../../../docs/DEVELOPER-GUIDE.md#L416) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

````markdown
docs/DEVELOPER-GUIDE.md:416: "All NicePay operations log with the `[NicePay]` prefix. If WooCommerce is active, logs go to `wp-content/uploads/wc-logs/nicepay-*.log`. Otherwise, they go to `wp-content/debug.log`." Hemen ardından satır 420-427 loglandığı iddia edilen liste: auth istek parametreleri, approval URL/parametreleri, approval yanıtı, imza doğrulama sonuçları, net cancel denemeleri, cancel istek/yanıtları, veritabanı hataları.
docs/USER-GUIDE.md:660 aynı iddiayı yapıyor: "**Without WooCommerce:** Check `wp-content/debug.log`."
Kod (includes/nicepay-functions.php:36-46):
```
if ( function_exists( 'wc_get_logger' ) ) { ... }
elseif ( 'error' === $level || 'warning' === $level ) {
    error_log( $log_entry );
}
```
Yani WooCommerce yoksa yalnızca `warning` ve `error` seviyeleri PHP error log'una gider; `debug` ve `info` seviyeleri sessizce atılır. Üstelik satır 21-23'e göre `debug` seviyesi WP_DEBUG kapalıyken zaten hiç üretilmiyor.
````

**Başarısızlık senaryosu**

WooCommerce kullanmayan bir site standalone ödemede approval hatası alıyor. Yönetici USER-GUIDE:650-660'a göre WP_DEBUG/WP_DEBUG_LOG'u açıyor ve `wp-content/debug.log`'a bakıyor. Approval isteği/yanıtı ve imza doğrulama satırları `debug` seviyesinde olduğu için dosyada hiç görünmüyor; yalnızca varsa bir `error` satırı var. Yönetici log'ların bozuk olduğunu düşünüp destek talebi açıyor.

**Etki**

Standalone (WooCommerce'siz) kurulumda teşhis yapan satıcı, dokümanın vaat ettiği approval/imza/istek kayıtlarını debug.log'da bulamaz ve entegrasyonun "hiç log üretmediği" sonucuna varır. Sorun giderme rehberleri (CONFIGURATION.md:461-468, USER-GUIDE:648-662) bu log'lara dayandığı için tüm teşhis akışı çıkmaza girer.

**Öneri**

docs/DEVELOPER-GUIDE.md:416'yı gerçeğe göre yazın: "WooCommerce etkinse tüm seviyeler `wc-logs/nicepay-*.log` dosyasına yazılır. WooCommerce yoksa yalnızca `warning` ve `error` seviyeleri PHP hata günlüğüne (`wp-content/debug.log`) düşer; `debug` seviyesi ayrıca `WP_DEBUG` gerektirir." Satır 420-427 listesine her maddenin seviyesini ekleyin. docs/USER-GUIDE.md:658-662'yi aynı şekilde düzeltin ve WooCommerce'siz kurulumlar için ayrıntılı teşhis isteyenlere geçici bir `nicepay_log` köprüsü yerine System Report sekmesini önerin. Alternatif olarak kodda WooCommerce yokken tüm seviyeleri WP_DEBUG_LOG'a yazacak bir dal ekleyin ve dokümanı olduğu gibi bırakın.

---

### DOC-009 — Developer Guide'daki `nicepay_save_transaction()` örneği güvensiz ve çalışmayan bir yazım öğretiyor (ham yanıt saklama + zorunlu bağlama alanlarının yokluğu)

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | unsafe-example |
| **Konum** | [docs/DEVELOPER-GUIDE.md:168](../../../docs/DEVELOPER-GUIDE.md#L168) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

````markdown
docs/DEVELOPER-GUIDE.md:168-181 örneği:
```php
$id = nicepay_save_transaction( array(
    'tid' => ..., 'moid' => ..., 'wc_order_id' => 42, 'amount' => 50000,
    'payment_method' => 'CARD', 'status' => 'paid', ...
    'payment_data'   => $full_response_array, // auto-serialized to JSON
) );
```
İki sorun: (a) `payment_data` içeriği filtrelenmiyor. includes/nicepay-functions.php:369-372 diziyi olduğu gibi `wp_json_encode` ediyor; içerik allowlist'i ayrı bir fonksiyonda (`nicepay_filter_payment_data`, satır 95-116) ve yalnızca çağrı yerlerinde uygulanıyor. `NicePay_Transaction_Schema::prepare_write()` (includes/class-nicepay-transaction-schema.php:257-275) sadece SÜTUN adlarını allowlist'liyor, `payment_data` içeriğine dokunmuyor. Yani örnekteki yazım maskeli PAN, token ve ham sağlayıcı alanlarını kalıcı olarak saklar — CHANGELOG.md:43-44'te "Removed fresh-schema PAN storage" ve "allowlisted persisted/provider response fields" olarak duyurulan güvenlik duruşunun tam tersi.
(b) Örnek `flow`, `source_ref`, `mid`, `mode`, `currency`, `edi_date` alanlarını hiç vermiyor; varsayılan listede (nicepay-functions.php:343-366) bunlar yok, dolayısıyla satır `flow=''`, `currency=''`, `mid=''` ile oluşur. `NicePay_Inbound_Validator` bu alanları bağlama için zorunlu kıldığından böyle bir satır hiçbir zaman onaylanamaz.
````

**Başarısızlık senaryosu**

Bir entegratör, kendi ödeme kanalını NicePay defterine yazmak için DEVELOPER-GUIDE:168-181'i kopyalar ve `$full_response_array` olarak NICEPAY yanıtının tamamını geçirir. `payment_data` sütununda `CardNo`, `AuthToken` ve diğer ham alanlar düz JSON olarak kalır; ayrıca `flow` boş olduğu için dönüş doğrulaması bu satırı hiçbir zaman eşleştiremez ve ödeme `needs_reconciliation` yerine hiç bağlanamaz.

**Etki**

Dokümana güvenerek kendi entegrasyonunu yazan bir geliştirici hem kişisel/kart verisini gereksiz yere kalıcı hale getirir (gizlilik ve PCI kapsamı riski) hem de asla onaylanamayacak yetim defter satırları üretir. Bu, ürünün kendi güvenlik iddialarını dokümantasyon seviyesinde geçersiz kılıyor.

**Öneri**

Örneği güvenli ve eksiksiz hale getirin ya da tamamen kaldırıp yalnızca okuma fonksiyonlarını belgeleyin. Güvenli sürüm:
```php
$id = nicepay_save_transaction( array(
    'flow'           => 'standalone',      // zorunlu bağlama alanı
    'source_ref'     => 'my-integration:123',
    'mid'            => $api->get_mid(),
    'mode'           => $api->get_mode(),
    'currency'       => 'KRW',
    'edi_date'       => $edi_date,
    'moid'           => $moid,
    'amount'         => '50000',
    'payment_method' => 'CARD',
    'status'         => 'pending',
    // Ham sağlayıcı yanıtını ASLA doğrudan geçmeyin:
    'payment_data'   => nicepay_filter_payment_data( $response ),
) );
```
Ayrıca `payment_data` satırındaki "auto-serialized to JSON" yorumunu "yalnızca `nicepay_filter_payment_data()` ile allowlist'lenmiş diziyi geçirin; fonksiyon içerik filtrelemesi yapmaz" ile değiştirin ve DEVELOPER-GUIDE:474-484'teki `json_decode( $tx->payment_data )` örneğinin de yalnızca allowlist'teki alanları (örn. `CardQuota`) içerdiğini not düşün.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: İddianın her iki bacağını da kodda doğruladım; satır referansları dosyaların şu anki haliyle eşleşiyor.

(a) `nicepay_save_transaction()` payment_data İÇERİĞİNİ filtrelemiyor: includes/nicepay-functions.php:371-372 diziyi olduğu gibi `wp_json_encode` ediyor. Tek allowlist katmanı `NicePay_Transaction_Schema::prepare_write()` (includes/class-nicepay-transaction-schema.php:257-275) ve o yalnızca SÜTUN adlarını süzüyor (`null === self::format_for( $column ) ) continue;`), değere dokunmuyor. İçerik allowlist'i ayrı fonksiyonda: `nicepay_filter_payment_data()` (includes/nicepay-functions.php:95-116) ve yalnızca çağrı yerlerinde uygulanıyor (includes/class-nicepay-gateway.php:580 ve :861). Eklentinin kendi tek `nicepay_save_transaction(` çağrısı (class-nicepay-gateway.php:269) doğru yapıyor; yani koruma sadece çağrı yerinde, fonksiyonda değil — dokümanı takip eden üçüncü taraf entegratör bu korumayı atlar. Ayrıca DEVELOPER-GUIDE.md içinde `nicepay_filter_payment_data` HİÇ geçmiyor (grep: 0 eşleşme), yani okuyucunun doğru yazımı öğrenebileceği başka bir yer de yok. Bu, CHANGELOG.md:43-44'teki "Removed fresh-schema PAN storage" / "allowlisted persisted/provider response fields" duruşuyla çelişiyor.

(b) Örnekteki zorunlu bağlama alanları gerçekten eksik ve varsayılanlarda yok: `$defaults` (includes/nicepay-functions.php:343-366) `flow`, `source_ref`, `mid`, `mode`, `currency`, `edi_date` içermiyor; bu sütunların şema varsayılanları boş string (`class-nicepay-transaction-schema.php:49,50,57,59,60,61` — ör. `'currency' => "char(3) NOT NULL DEFAULT ''"`). `NicePay_Inbound_Validator::validate_auth_return()` bu boş değerlerde fail-closed dönüyor: satır 68 (`flow` uyuşmazlığı), 87-90 (`'' === $stored_mid` -> mid_mismatch), 93-94 (`'KRW' !== strtoupper(currency)` -> currency_mismatch); onay tarafında da aynısı 145-149 ve 157-158. Yani örnekteki yazımla oluşan satır hiçbir zaman dönüş/onay doğrulamasıyla eşleşemez.

Konum sapmaları ihmal edilebilir düzeyde: doküman örneği ```php çiti 168'de, çağrı 170'te, `payment_data` satırı 180'de, blok 181'de kapanıyor; `json_decode( $tx->payment_data, true )` 480. satırda (iddiadaki 474-484 aralığı bu bloğu kapsıyor). Diğer tüm referanslar (nicepay-functions.php:95, :369-372, schema:257) birebir doğru. Severity `medium` uygun: sömürü yolu doğrudan eklenti kodunda değil, ama yayımlanmış doküman güvensiz ve çalışmayan bir yazım öğretiyor.

---

### DOC-010 — API-REFERENCE.md'de bozuk markdown tablosu — Approval Response satırlarının yarısı düz metin olarak render oluyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | rendering-defect |
| **Konum** | [docs/API-REFERENCE.md:167](../../../docs/API-REFERENCE.md#L167) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

````markdown
"Common Response" tablosu satır 158-165'te başlıyor, sonra satır 167-171'de tablonun ortasına düz bir paragraf giriyor:
```
| Signature | 500 | hex(sha256(TID + MID + Amt + MerchantKey)) |

For approval-response signature verification, `Amt` is used byte-for-byte as
returned by NicePay. ...
| TID | 30 | Transaction ID |
| AuthCode | 30 | Authorization code |
| AuthDate | 12 | Authorization date (`YYMMDDHHMMSS`) |
| PayMethod | 10 | Payment method code |
```
Paragraf tabloyu kapattığı için satır 172-175'teki dört satır GitHub/WP render'ında tablo olarak değil, ham `| TID | 30 | ... |` metni olarak görünür.
Ayrıca docs/DEVELOPER-GUIDE.md:124'te `$moid = $api->generate_moid( 'CUSTOM' ); // CUSTOM_20260403120000_1234` yorumu yanlış: includes/class-nicepay-api.php:82-92 sonek olarak `bin2hex( random_bytes( 8 ) )` yani 16 haneli hex üretiyor, 4 haneli sayı değil.
````

**Başarısızlık senaryosu**

Bir geliştirici approval yanıtını kendi sisteminde eşlemek için docs/API-REFERENCE.md'nin "Approval Response Parameters > Common Response" tablosunu okur. GitHub'da tablo `Signature` satırından sonra kapandığı ve devamı ham metin olarak göründüğü için `AuthDate` formatını (`YYMMDDHHMMSS`) gözden kaçırır ve tarihi `YYYYMMDD` olarak ayrıştırmaya çalışır.

**Etki**

Protokolün en kritik referans tablosu (approval yanıtının TID/AuthCode/AuthDate/PayMethod alanları) okunaksız hale geliyor; entegratör bu alanların belgelenmediğini sanabilir. Moid örneği ise entegratörün Moid uzunluk/format varsayımını (64 karakter sınırı, hex sonek) yanlış kurmasına yol açar.

**Öneri**

Paragrafı tablonun ALTINA taşıyın: satır 158-175'i tek bir kesintisiz tablo yapın (Signature, TID, AuthCode, AuthDate, PayMethod aynı blokta), imza/canonicalization açıklamasını tablodan sonra bir not olarak verin. `docs/DEVELOPER-GUIDE.md:124`'teki yorumu `// CUSTOM_20260403120000_a1b2c3d4e5f60718 (16 hane hex)` olarak düzeltin. Uzun vadede CI'a bir markdown lint adımı (örn. markdownlint MD055/MD056 tablo kuralları) ekleyerek bu sınıfın tekrarını engelleyin — repo zaten JS/CSS/çeviri için lint kapıları çalıştırıyor.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: İki iddianın da kodu/dosyayı doğrudan okudum ve ikisi de doğru.

1) Tablo kırılması: docs/API-REFERENCE.md'de "Common Response" tablosu 158. satırda başlıyor (`| Parameter | Size | Description |` + 159'da ayırıcı), 165'te `Signature` satırıyla bitiyor. 166 BOŞ satır. 167-171 arası düz bir paragraf var. Ardından 172-175'te tekrar tablo satırları geliyor ama tablonun başlık/ayırıcı satırı olmadan. GFM tablo grameri gereği tablo, boş satırla (166) kapanır; 167'de yeni bir paragraf başlar ve 172-175 satırları bu paragrafın "lazy continuation" satırları olarak paragrafın içine emilir — yani `| TID | 30 | Transaction ID |` vb. ham metin olarak render olur, tablo olarak DEĞİL. Bu satırların önünde yeni bir `|---|---|---|` ayırıcısı olmadığı için ayrı bir tablo olarak da parse edilemez. İddiayı çürütecek herhangi bir koruma (HTML tablosu, ayrı ayırıcı satırı, `<!-- -->` ayracı) yok.

2) Moid örneği: docs/DEVELOPER-GUIDE.md:124'teki yorum `// CUSTOM_20260403120000_1234` diyor. includes/class-nicepay-api.php:91 ise soneki `bin2hex( random_bytes( 8 ) )` ile üretiyor = 16 haneli hex. Yani gerçek çıktı `CUSTOM_20260403120000_a1b2c3d4e5f60718` biçiminde; dokümandaki 4 haneli sayı örneği yanlış ve entegratörün uzunluk varsayımını (prefix<=32 + 1 + 14 + 1 + 16) yanlış kurmasına yol açar.

severity `medium` uygun: sadece dokümantasyon, çalışma zamanı davranışı etkilenmiyor; ama protokolün en kritik referans tablosu okunaksız hale geliyor.

---

### DOC-011 — `Tested up to: 7.0` iddiasının repoda hiçbir kanıtı yok; WooCommerce sürüm beyanı da plugin header'da eksik

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | unsubstantiated-claim |
| **Konum** | [readme.txt:4](../../../readme.txt#L4) |
| **Güven** | medium |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

```text
readme.txt:4 `Tested up to: 7.0`. Repoda bu sürümü doğrulayan hiçbir kanıt yok: entegrasyon testlerinde WordPress imajı yalnız digest ile sabitlenmiş ve sürüm yorumu YOK — tests/integration/run-woocommerce-smoke.sh:12 `wordpress_image='wordpress@sha256:b427...'` (kardeş satırlar 11 ve 13'te `# 10.11` ve `# CLI 2.12.0, PHP 8.2` yorumları var, WordPress satırında yok); aynı durum tests/integration/run-schema-migration.sh:12'de. `.github/scripts/check-version.js` yalnızca `Requires at least`ı ('5.8') doğruluyor (satır 81-83), `Tested up to`yu hiç kontrol etmiyor.
Buna karşılık docs/WORDPRESS-ORG-RELEASE.md:85 açıkça "`Tested up to` yalnızca gerçekten test edilmiş WordPress ana sürümünü göstermelidir... test matrisi geçtikten sonra güncellenmelidir" diyor ve satır 184'te bunu bir yayın ön koşulu yapıyor.
Ayrıca nicepay-payment-gateway.php:1-16 header'ında `WC requires at least: 5.0` var ama `WC tested up to:` yok — oysa README.md:141 ve CHANGELOG.md:16 "WooCommerce 11.0.1 smoke matrix" iddiasında bulunuyor. docs/CONFIGURATION.md:420 ise WordPress için "Recommended 6.0+" diyerek 7.0 iddiasıyla aynı sayfada durmuyor.
```

**Başarısızlık senaryosu**

Yayın öncesi kontrol listesi (docs/WORDPRESS-ORG-RELEASE.md:184) "`Tested up to` ... test kanıtıyla eşleşiyor" maddesini işaretlemeye çalışan kişi, repoda hangi WordPress sürümünün test edildiğini gösteren tek bir satır bulamaz (imaj digest'i sürümsüz). Kanıt üretilemediği için ya madde kanıtsız işaretlenir ya da yayın bloke olur.

**Etki**

WordPress.org listesinde "Tested up to" doğrudan kullanıcıya gösterilen bir uyumluluk beyanıdır ve yanlışsa hem WP.org inceleme ekibi hem satıcı yanılır. Aynı anda proje kendi yayın rehberinde bu alanı kanıtla eşlemeyi şart koştuğu için doküman kendi kuralını ihlal ediyor. `WC tested up to` eksikliği ise WooCommerce'in "uyumsuz eklenti" uyarısını tetikleyebilir.

**Öneri**

1) tests/integration/*.sh içindeki `wordpress_image` digest'ine kardeşleriyle aynı biçimde sürüm yorumu ekleyin (`# WordPress X.Y`) ve readme.txt'deki `Tested up to` değerini o sürüme eşitleyin.
2) `.github/scripts/check-version.js`'e readme.txt `Tested up to` değerini entegrasyon test imajının sürümüyle karşılaştıran bir kontrol ekleyin — proje zaten Stable tag/Requires at least/License için mekanik kapılar kuruyor.
3) nicepay-payment-gateway.php header'ına `WC tested up to: 11.0` ekleyin (gerçekten smoke edilen sürüm) veya README.md:141'deki 11.0.1 iddiasını header ile hizalayın.
4) docs/CONFIGURATION.md:420'deki "Recommended 6.0+" satırını güncel test edilen sürüme çekin.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: İddianın her bileşenini dosyaları açarak doğruladım; hem satır numaraları hem alıntılar dosyaların şu anki haliyle bire bir eşleşiyor.

1) readme.txt:4 gerçekten `Tested up to: 7.0` (dosyada `Contributors:` satırı yok; başlık satır 1, dolayısıyla satır numarası doğru).
2) Repo genelinde `grep -rn "Tested up to"` (node_modules ve .git hariç) sadece 4 isabet veriyor: readme.txt:4, docs/WORDPRESS-ORG-RELEASE.md:85 ve :184, artı eski durum analizini anlatan docs/analysis/* dosyaları. Yani 7.0'ı doğrulayan tek bir test kanıtı/çalıştırma çıktısı yok.
3) tests/integration/run-woocommerce-smoke.sh:12 ve tests/integration/run-schema-migration.sh:12'de `wordpress_image` yalnız digest ile sabit ve sürüm yorumu YOK; kardeş satır 11 (`# 10.11`) ve 13 (`# CLI 2.12.0, PHP 8.2`) yorumlu. İki dosya aynı digest'i kullanıyor. Betiklerde `wp core version` benzeri bir sürüm doğrulaması/çıktısı da yok (`grep` yalnız `wp core install` satırlarını buluyor: run-schema-migration.sh:92, run-woocommerce-smoke.sh:95).
4) .github/scripts/check-version.js'de `Tested` kelimesi hiç geçmiyor; WordPress'e dair tek kontrol satır 67'deki `Requires at least` regex'i ve satır 81-83'teki `!== '5.8'` kapısı. readme.txt'nin `Tested up to` alanı mekanik olarak hiç doğrulanmıyor.
5) nicepay-payment-gateway.php header'ında (satır 1-16) `WC requires at least: 5.0` var, `WC tested up to:` YOK — repo genelinde bu alan yalnızca docs/analysis/* içindeki eski-durum metinlerinde geçiyor, eklenti dosyasında değil. Buna karşılık README.md:141 ve CHANGELOG.md:16 "WooCommerce 11.0.1 smoke matrix" iddiasında bulunuyor; README.md:32 de yalnız `WooCommerce | 5.0+` diyerek tavan beyan etmiyor.
6) docs/CONFIGURATION.md:420 `| WordPress | 5.8 | 6.0+ |` — readme.txt'deki 7.0 beyanıyla aynı hikâyeyi anlatmıyor.
7) Proje kendi kuralını docs/WORDPRESS-ORG-RELEASE.md:85'te koyuyor ("`Tested up to` yalnızca gerçekten test edilmiş... test matrisi geçtikten sonra güncellenmelidir") ve :184'te yayın ön koşulu yapıyor — yani kanıtsız 7.0 beyanı doğrudan kendi checklist maddesini karşılanamaz hale getiriyor.

Çürütücü bir koruma aradım ve bulamadım: ne CI'da bir `Tested up to` kapısı, ne betiklerde sürüm assert'i, ne de docs'ta digest→sürüm eşlemesi var. Tek kalifikasyon: digest'in gerçekten WP 7.0 imajına işaret ediyor olması mümkündür (çevrimdışı doğrulanamaz) — ancak iddia zaten "repoda kanıt yok" diyor, bu yüzden çürütülmüyor. `WC tested up to` eksikliğinin WooCommerce uyarısını tetikleyip tetiklemediği (eksik vs. eski değer davranışı) etki cümlesinde ufak bir belirsizlik, çekirdek bulguyu etkilemiyor. Severity `medium` uygun: yayın bloklayıcı değil ama WP.org incelemesi ve tüccar güveni için doğrudan görünür bir beyan.

---

### DOC-012 — Doküman içi çelişkiler: BANK nakit makbuzu ve CELLPHONE GoodsCl davranışı; `goods_class` alanı hiçbir parametre tablosunda yok

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | internal-contradiction |
| **Konum** | [docs/USER-GUIDE.md:486](../../../docs/USER-GUIDE.md#L486) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

```markdown
1) Nakit makbuz çelişkisi: docs/USER-GUIDE.md:486 `- **Features:** Cash receipt issuance (income deduction or expense proof)` diyerek BANK için nakit makbuzu bir ÖZELLİK olarak sunuyor. Aynı dokümanın satır 158'i "Cash-receipt workflows are not supported", docs/CONFIGURATION.md:96 "Cash-receipt workflows are not supported" ve readme.txt:61 "tax or cash-receipt features are not supported" diyor. Kodda da yalnız protokol alanları (`RcptType`, `RcptTID`) API-REFERENCE'ta listeleniyor, hiçbir akış yok.
2) GoodsCl çelişkisi: docs/USER-GUIDE.md:498 `- **Product type:** Automatically set to "physical goods" (GoodsCl=1)`. Gerçekte includes/nicepay-functions.php:566-587 sipariş kalemlerine bakıyor: tüm ürünler sanal ise `'0'`, aksi halde `'1'`; sonuç `nicepay_goods_cl` filtresiyle değiştirilebiliyor. docs/CONFIGURATION.md:97 ise "Set `GoodsCl` (content/physical)" diyerek kullanıcının seçtiğini ima ediyor — WooCommerce akışında kullanıcı seçmiyor.
3) `goods_class` alanı: nicepay_get_default_presets() (includes/nicepay-functions.php:1743, 1765, 1787) her preset'te `goods_class` tutuyor ve nicepay-payment-gateway.php:439/464 bunu shortcode akışında kullanıyor. README.md:99-113 ve docs/USER-GUIDE.md:423-437 parametre tablolarının ikisinde de `goods_class` yok; docs/DEVELOPER-GUIDE.md:231-249'daki kayıtlı shortcode yapısı da `goods_class` ve `preset_version` alanlarını atlıyor.
```

**Başarısızlık senaryosu**

Dijital abonelik satan bir mağaza CELLPHONE'u açar. USER-GUIDE:498'e göre GoodsCl her zaman `1` (fiziksel) sanır ve taşıyıcının içerik limitleriyle ilgili herhangi bir hazırlık yapmaz. Gerçekte tüm ürünler sanal olduğu için kod `'0'` gönderir; mobil taşıyıcı içerik limitleri ve reddi farklı davranır, mağaza sebebini dokümandan çözemez.

**Etki**

Satıcı BANK'ı nakit makbuz beklentisiyle etkinleştirebilir ve Kore'de yasal olarak beklenen makbuzun üretilmediğini müşteri şikâyetiyle öğrenir. CELLPHONE tarafında ise dijital ürün satan bir mağaza, dokümana göre GoodsCl'in her zaman 1 olduğunu sanıp taşıyıcı limitleri/uyum konusunda yanlış varsayım kurar. `goods_class` alanının belgesiz olması, kayıtlı konfigürasyonu programatik üreten entegrasyonların bu alanı boş bırakmasına yol açar.

**Öneri**

docs/USER-GUIDE.md:486'daki "Cash receipt issuance" maddesini kaldırın veya "Protokolde makbuz alanları vardır; bu eklenti nakit makbuz akışını uygulamaz" olarak değiştirin. Satır 498'i gerçek mantıkla değiştirin: "GoodsCl, WooCommerce sipariş kalemlerinden türetilir — tüm kalemler sanal ise `0`, aksi halde `1`. `nicepay_goods_cl` filtresiyle geçersiz kılınabilir; standalone formlarda kayıtlı `goods_class` alanı kullanılır." docs/CONFIGURATION.md:97'yi aynı şekilde düzeltin. README.md ve USER-GUIDE parametre tablolarına `goods_class` satırını (kayıtlı konfigürasyondan gelir, shortcode ile geçersiz kılınamaz) ve DEVELOPER-GUIDE:231-249 yapısına `goods_class` + `preset_version` alanlarını ekleyin.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: Üç alt iddianın üçünü de dosyaları açarak doğruladım; hepsi gerçek ve birincil satır numaraları birebir tutuyor. Çürütücü bir koruma aramak için nakit makbuz akışını (`RcptType`/`RcptTID`) ve `goods_class`'ın shortcode akışındaki kullanımını grep'ledim — eklenti kodunda hiçbir nakit makbuz işleme/kaydetme/gösterme akışı yok (yalnız docs/API-REFERENCE.md:213-214'te protokol alanı olarak listeleniyor, ve docs/analysis/12-path-to-first-class.md:769 bu alanların "discarded" olduğunu açıkça yazıyor), dolayısıyla USER-GUIDE:486'nın "Features: Cash receipt issuance" ifadesi aynı dokümanın 158. satırı, CONFIGURATION.md:96 ve readme.txt:23/61 ile doğrudan çelişiyor.

GoodsCl için de USER-GUIDE:498 "Automatically set to physical goods (GoodsCl=1)" derken kod (includes/nicepay-functions.php:566-587) sipariş kalemlerinden türetiyor: tüm kalemler sanal ise '0'. `nicepay-payment-gateway.php:686` ise standalone akışta kayıtlı `goods_class`'ı ('0'|'1') kullanıyor ve preset'lerde iki taneside '0' (quick-payment, donation). Yani dijital ürün senaryosunda gerçekten '0' gönderiliyor — başarısızlık senaryosu geçerli.

`goods_class` alanının belgesizliği de doğrulandı: dokümanlarda tek geçiş yalnızca docs/analysis/05-user-experience.md:979 (bir öneri metni); README.md:99-113 ve USER-GUIDE parametre tablolarında yok, DEVELOPER-GUIDE.md:229-250'deki kayıtlı shortcode yapısında ne `goods_class` ne `preset_version` var — oysa `preset_version` kodda işlevsel (nicepay-functions.php:1873, 1895 preset güncelleme/koruma mantığı, class-nicepay-privacy.php:190 export'ta temizleniyor).

Severity `medium` uygun: yasal/uyum beklentisi yaratan yanlış doküman + belgesiz veri alanı, ama çalışma zamanı güvenlik/veri kaybı yok.

---

### DOC-013 — Dil tutarsızlığı ve yönetilmeyen iç analiz arşivi: 15 doküman Türkçe, indekssiz ve kendini "commit edilmemiş" olarak tanımlıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | consistency-maintenance |
| **Konum** | [docs/WORDPRESS-ORG-RELEASE.md:1](../../../docs/WORDPRESS-ORG-RELEASE.md#L1) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```markdown
docs/WORDPRESS-ORG-RELEASE.md tamamen Türkçe (satır 1: "# WordPress.org Yayın Hazırlığı ve Güvenli Dağıtım"), oysa README.md, readme.txt, CHANGELOG.md, SECURITY.md, CONTRIBUTING.md, CODE_OF_CONDUCT.md ve diğer 5 docs/*.md dosyasının hepsi İngilizce; ürünün hedef kitlesi WordPress.org (global) ve Kore pazarı.
docs/analysis/ altındaki 14 dosya (~14.700 satır, bu PR'da eklendi) da Türkçe. Bu arşiv hiçbir yerden referanslanmıyor (`grep -rn "analysis/" README.md CONTRIBUTING.md docs/*.md .github` sonuç vermiyor) ve içeriği güncel değil: docs/analysis/14-remediation-progress.md:3-6 "**Son güncelleme:** 2026-08-20", "**Durum:** Değişiklikler çalışma ağacında; commit veya push yapılmadı." — oysa dosya artık commit edilmiş ve kod 2026-08-25'e kadar değişmiş (languages/nicepay-payment-gateway.pot POT-Creation-Date: 2026-08-25, NicePay_Transaction_Schema::VERSION = '2026.08.24.7').
Ayrıca README.md:178-184 "Documentation" listesi 5 dokümanı sayıyor; WORDPRESS-ORG-RELEASE.md ve analysis arşivi bu indekste yok.
```

**Başarısızlık senaryosu**

Projeye katılan İngilizce konuşan bir maintainer WordPress.org yayınını üstlenir. `docs/WORDPRESS-ORG-RELEASE.md`'yi açar, tamamen Türkçe olduğu için okuyamaz ve workflow'u kendi varsayımlarıyla `publish` modunda çalıştırır; slug/marka ve `Update URI` kapılarının neden var olduğunu bilmediği için hataları yanlış yorumlar. Ayrı bir senaryoda yeni bir katkıcı docs/analysis/14'ü okur, "commit veya push yapılmadı" ifadesine bakarak çalışmanın hâlâ beklemede olduğunu sanır.

**Etki**

Türkçe bilmeyen bir katkıcı veya WordPress.org inceleyicisi, yayın prosedürünün tamamını okuyamaz — ki bu, marka/slug ve üçüncü taraf hizmet kararlarını içeren en riskli dokümandır. İndekslenmemiş ve kendi durumu hakkında yanlış beyanda bulunan 14.700 satırlık analiz arşivi ise kalıcı bakım yükü yaratır ve kod ilerledikçe sessizce yanlışa dönüşür.

**Öneri**

1) docs/WORDPRESS-ORG-RELEASE.md'yi İngilizceye çevirin (gerekiyorsa Türkçe sürümü `docs/tr/` altında ikinci sınıf kopya olarak tutun) ve README.md:178-184 Documentation listesine ekleyin.
2) docs/analysis için bir karar verin: (a) repodan çıkarıp ayrı bir dahili arşive taşıyın, veya (b) `docs/analysis/README.md` ekleyip "2026-08 tarihli tek seferlik denetim anlık görüntüsü; kod ilerledikçe güncellenmez" diye açıkça donmuş ilan edin ve 14-remediation-progress.md:6'daki "commit veya push yapılmadı" ifadesini düzeltin.
3) Yeni dokümanlar için CONTRIBUTING.md'ye tek satırlık bir kural ekleyin: "Tüm kalıcı dokümantasyon İngilizce yazılır."

---

### DOC-014 — Alıcı alanı doğrulama kuralları ve bayt sınırları yanlış/eksik belgelenmiş; CONFIGURATION.md dört dil kataloğundan üçünü sayıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | reference-accuracy |
| **Konum** | [docs/USER-GUIDE.md:452](../../../docs/USER-GUIDE.md#L452) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```markdown
1) docs/USER-GUIDE.md:449-452: "Phone: must be 7-20 digits (allows dashes, spaces, parentheses)". İki gerçek kural var ve ikisi de bundan farklı: istemci assets/js/nicepay.js:217 `/^[\d\-+() ]{7,30}$/` (7-30 karakter, `+` da kabul), sunucu includes/nicepay-functions.php:1446 `/^[0-9+() -]{7,20}$/` (7-20 karakter, `+` kabul). Doküman `+` işaretini hiç anmıyor ve "digits" diyerek karakter sınırını rakam sınırı gibi gösteriyor.
2) Alıcı alanlarının UTF-8 BAYT sınırları hiçbir kullanıcı dokümanında yok: includes/nicepay-functions.php:1396-1401 `buyer_name => 30`, `buyer_tel => 20`, `buyer_email => 60` bayt; aşımda `nicepay_buyer_fields_too_long` hatası dönüyor. Kullanıcı dokümanlarında yalnızca `goods_name` için 40 bayt notu var (README.md:104, docs/USER-GUIDE.md:204, 428).
3) docs/CONFIGURATION.md:387-391 çeviri dosyalarını sayarken yalnızca ko_KR, en_US ve zh_CN'i listeliyor; repoda dört katalog var (languages/nicepay-payment-gateway-tr_TR.po/.mo dahil) ve README.md:22 ile docs/USER-GUIDE.md:588-592 Türkçe'yi doğru şekilde sayıyor.
```

**Başarısızlık senaryosu**

Kore'de 11 Hangul karakterli ada sahip bir alıcı standalone formu doldurur. İstemci doğrulaması geçer, sunucu `strlen()` ile 33 bayt gördüğü için `nicepay_buyer_fields_too_long` döner ve ödeme başlamaz. Yönetici docs/USER-GUIDE.md:449-454'teki doğrulama listesine bakar; orada yalnız "Name: must not be empty" yazdığı için hatayı reprodüksiyon edemez ve eklentinin bozuk olduğunu düşünür.

**Etki**

İstemci tarafı kabul edip sunucunun reddettiği bir aralık (21-30 karakterlik telefon) var ve kullanıcı dokümanı ikisini de doğru anlatmadığı için destek tarafı hatayı teşhis edemez. Bayt sınırlarının belgesizliği özellikle Korece için ciddi: 30 bayt ≈ 10 Hangul karakteri; uzun isimli alıcılar "Buyer information exceeds the payment provider field limits." hatası alır ve ne yöneticinin ne alıcının başvurabileceği bir doküman vardır.

**Öneri**

docs/USER-GUIDE.md:449-454 doğrulama listesini gerçek kurallarla değiştirin: "Ad: boş olamaz, UTF-8 olarak en fazla 30 bayt (yaklaşık 10 Hangul veya 30 Latin karakter). E-posta: geçerli format, en fazla 60 bayt. Telefon: 7-20 karakter; rakam, `+`, `-`, boşluk ve parantez kabul edilir." İstemci regex'ini (7-30) sunucu sözleşmesiyle (7-20) hizalayın ki doküman tek bir kuralı anlatabilsin. docs/CONFIGURATION.md:387-391 listesine `nicepay-payment-gateway-tr_TR.po — Turkish` satırını ekleyin.

---

### DOC-015 — CONTRIBUTING.md gerçek PR şablonunun eski bir kopyasını gömüyor ve ürünün yasakladığı VBANK özelliğini örnek commit olarak veriyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | duplication-drift |
| **Konum** | [CONTRIBUTING.md:116](../../../CONTRIBUTING.md#L116) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```markdown
CONTRIBUTING.md:116-141 "### PR Template" başlığı altında bir markdown bloğu gömüyor. Bu blok .github/PULL_REQUEST_TEMPLATE.md ile artık uyuşmuyor: gerçek şablonda `Security or release hardening` tipi (satır 11) ve `composer test` / `composer quality` / "Breaking changes and migration requirements are documented" checklist maddeleri (satır 19-25) var; CONTRIBUTING kopyasında bunlar yok, buna karşılık gerçek şablonda olmayan bir "## Changes" bölümü var.
Ayrıca CONTRIBUTING.md:250 commit mesajı örneği: `feat: add deposit notification handler for virtual accounts` — VBANK, README.md:5, readme.txt:23, docs/DEVELOPER-GUIDE.md:374-376 ve docs/CONFIGURATION.md:99'da açıkça yasaklı ve "DG-01/DG-02 olmadan eklenmemeli" denen özellik.
```

**Başarısızlık senaryosu**

Yeni bir katkıcı CONTRIBUTING.md:118-141'i okuyup PR açar; GitHub farklı bir şablon doldurur, katkıcı `composer quality` maddesini hiç görmediği için lint kapılarını çalıştırmadan gönderir ve CI'da düşer. Ayrı olarak, aynı katkıcı satır 250'deki örnekten esinlenip VBANK deposit handler'ı için PR hazırlar; iş, sertifikasyon eksikliği nedeniyle reddedilir.

**Etki**

İki kaynaklı PR şablonu kaçınılmaz olarak ayrışır; katkıcı hangi checklist'in geçerli olduğunu bilemez ve GitHub zaten gerçek şablonu otomatik doldurduğu için CONTRIBUTING kopyası yalnızca kafa karışıklığı üretir. VBANK örneği ise yeni katkıcıya, ürünün bilinçli olarak kapattığı bir özelliğin beklenen bir katkı olduğunu ima ediyor.

**Öneri**

CONTRIBUTING.md:116-141'deki gömülü bloğu silin ve yerine tek satır koyun: "PR açtığınızda GitHub `.github/PULL_REQUEST_TEMPLATE.md` şablonunu otomatik yükler; tüm maddeleri doldurun." Satır 250'deki commit örneğini ürün politikasıyla uyumlu bir örnekle değiştirin, örn. `feat: add reconciliation filter to the transactions screen`.

---

### DOC-016 — SECURITY.md'de SLA, desteklenen sürüm tablosu ve açıklama takvimi yok; CoC taciz bildirimini herkese açık issue'ya yönlendiriyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | policy-completeness |
| **Konum** | [SECURITY.md:3](../../../SECURITY.md#L3) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```markdown
SECURITY.md 20 satır. "## Supported versions" bölümü (satır 3-5) yalnızca "Security fixes are applied to the latest released version." diyor — sürüm/tarih tablosu yok. Satır 18: "The maintainers will acknowledge the report, validate its impact, and coordinate a fix and disclosure timeline" — somut bir ilk yanıt süresi (örn. 5 iş günü), düzeltme hedefi veya koordineli açıklama penceresi (örn. 90 gün) yok. Safe-harbor / araştırmacı taahhüdü yok.
CODE_OF_CONDUCT.md:28: "please report it by opening a GitHub Issue or contacting the maintainer directly." — özel bir kanal (e-posta) verilmiyor; CONTRIBUTING.md:22 de aynı şekilde "Please report unacceptable behavior via GitHub Issues" diyor.
```

**Başarısızlık senaryosu**

Bir araştırmacı GHSA üzerinden imza doğrulamayla ilgili bir açık bildirir. İki hafta yanıt alamaz; SECURITY.md'de hiçbir yanıt taahhüdü olmadığı için ne zaman eskalasyon yapacağını bilemez ve 30. günde bulguyu bloglar. Satıcılar yamasız kalır.

**Etki**

Bir ödeme eklentisi için güvenlik araştırmacısının bekleyebileceği taahhüt seviyesi (yanıt süresi, açıklama penceresi) yazılı değil; araştırmacı belirsizlik nedeniyle doğrudan kamuya açıklamayı seçebilir. CoC tarafında taciz mağdurunun tek yönlendirilen kanalı herkese açık bir issue olması, bildirimi caydırır.

**Öneri**

SECURITY.md'ye ekleyin: (a) desteklenen sürüm tablosu (`| 2.0.x | ✅ | 1.x | ❌ |`), (b) somut SLA ("ilk yanıt 5 iş günü, üçgenleme 10 iş günü, koordineli açıklama varsayılan 90 gün"), (c) araştırmacı için safe-harbor ifadesi ve kapsam (üretim satıcı sitelerinde test yasak, yalnız kendi kurulumunuzda), (d) düzeltme yayınlandıktan sonra credit politikası. CODE_OF_CONDUCT.md:28 ve CONTRIBUTING.md:22 için herkese açık issue yerine özel bir kanal (e-posta adresi veya GitHub private report) belirtin.

---

### DOC-017 — Sorun giderme adımı tarayıcı-tarafı kaynağı sunucudan doğrulamaya yönlendiriyor; sürüm ZIP'i katkıcı dokümanlarını son kullanıcıya gönderiyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | guidance-accuracy |
| **Konum** | [docs/CONFIGURATION.md:456](../../../docs/CONFIGURATION.md#L456) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```markdown
docs/CONFIGURATION.md:450-458 "Payment window doesn't open" çözümü: "2. Verify `https://pg-web.nicepay.co.kr/v3/common/js/nicepay-pgweb.js` is accessible **from your server**". Bu dosya müşterinin tarayıcısı tarafından yükleniyor (nicepay-payment-gateway.php:29 `NICEPAY_JS_URL`, enqueue_payment_assets ile frontend'e ekleniyor); sunucudan erişilebilirliği alakasız. Aynı dokümanın kendi güvenlik duvarı tablosu (satır 362-365) da yalnızca API IP'lerini listeliyor, pg-web'i listelemiyor — yani doküman kendi içinde de tutarsız. README.md:198 doğru şekilde tarayıcı konsolunu işaret ediyor.
Ayrıca .github/scripts/build-release.sh:58-64 sürüm ZIP'ine `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md` ve beş geliştirici dokümanını da kopyalıyor; docs/WORDPRESS-ORG-RELEASE.md:25 ise gönderilen ZIP'in "Geliştirme araçları, testler ... içermemelidir" diyor. README.md:178-184 doküman indeksi de docs/WORDPRESS-ORG-RELEASE.md'yi listelemiyor.
```

**Başarısızlık senaryosu**

Sunucudan giden istekleri sıkı bir allowlist ile sınırlayan bir barındırmada ödeme penceresi açılmıyor. Yönetici CONFIGURATION.md:456'yı izleyip sunucudan `curl https://pg-web.nicepay.co.kr/...` dener, başarısız olur ve barındırmadan pg-web için outbound kural açmasını ister — gerçek sorun ise müşterinin tarayıcısındaki CSP'dir ve çözülmeden kalır.

**Etki**

Yanlış sorun giderme adımı zaman kaybettirir ve gereksiz güvenlik duvarı kuralı taleplerine yol açar (README.md:176 tam da bunu yapmamayı öğütlüyor). Katkıcı dokümanlarının son kullanıcı paketinde bulunması hem gereksiz yük hem de WP.org inceleme sürtünmesi yaratır.

**Öneri**

docs/CONFIGURATION.md:456'yı "Müşterinin tarayıcısından bu URL'in yüklendiğini doğrulayın (sunucu tarafı erişimi gerekmez); tarayıcı konsolunda ağ/CSP hatasını kontrol edin" olarak düzeltin ve 457'deki CSP maddesini öne alın. build-release.sh:58-64 listesinden `CONTRIBUTING.md` ve `CODE_OF_CONDUCT.md`'yi çıkarın (README/LICENSE/CHANGELOG/SECURITY kalabilir) ya da WORDPRESS-ORG-RELEASE.md:25'i paket içeriğiyle uyumlu hale getirin. README.md:178-184 doküman indeksine WORDPRESS-ORG-RELEASE.md'yi ekleyin.

---

### DOC-018 — API-REFERENCE `EdiType`'ı "JSON default" diye belgeliyor; eklenti bu parametreyi hiç göndermiyor ama JSON yanıtına mutlak bağımlı

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | protocol-assumption |
| **Konum** | [docs/API-REFERENCE.md:150](../../../docs/API-REFERENCE.md#L150) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```markdown
docs/API-REFERENCE.md:150: `| EdiType | 10 | No | Response format (JSON default, KV for key=value) |`. Eklentinin approval isteği bu alanı içermiyor — includes/class-nicepay-api.php:347-360'daki gövde yalnızca TID, AuthToken, MID, Amt, EdiDate, SignData ve CharSet gönderiyor (`grep -n "EdiType" includes/class-nicepay-api.php` hiç eşleşme vermiyor). Buna karşılık yanıt zorunlu olarak JSON olarak ayrıştırılıyor: satır 291-299 civarında `json_decode( $body, true )` sonucu dizi değilse istek hata sayılıyor. Yani ürün, dokümanda "vendor varsayılanı" olarak yazılan ama repoda hiçbir fixture ile doğrulanmayan bir davranışa bağımlı — üstelik aynı doküman DG-02 kapsamında sanitize edilmiş vendor fixture'larının eksik olduğunu satır 7'de kabul ediyor.
```

**Başarısızlık senaryosu**

Satıcının canlı MID'i KV yanıt varsayılanıyla sağlanmıştır. İlk canlı ödemede approval yanıtı `ResultCode=3001&...` biçiminde gelir, `json_decode` null döner ve eklenti `needs_reconciliation`/net-cancel yoluna girer. Ekip API-REFERENCE:150'deki "JSON default" ifadesine güvendiği için nedeni protokol tarafında aramaz.

**Etki**

MID veya vendor konfigürasyonu KV varsayılanına sahipse tüm onaylar "malformed response" olarak başarısızlığa düşer ve doküman bunun bir varsayım olduğunu okuyucuya söylemez. Bu, DG-02 kontrol listesine girmesi gereken somut bir bağımlılığın belgelenmemesi demektir.

**Öneri**

docs/API-REFERENCE.md:150 satırına açık bir not ekleyin: "Bu eklenti `EdiType` göndermez ve yanıtı JSON olarak ayrıştırır. MID'inizin varsayılan yanıt biçiminin JSON olduğunu DG-01 kapsamında yazılı olarak doğrulayın; KV varsayılanı tüm onayları başarısız kılar." Aynı maddeyi docs/DEVELOPER-GUIDE.md:450-452 "Important Test Mode Notes" listesine DG-02 kalemi olarak ekleyin. En sağlamı: kodda isteğe `'EdiType' => 'JSON'` ekleyip dokümanı buna göre güncelleyin.

---

### DOC-019 — CHANGELOG 43k satırlık bir major sürüm için Keep a Changelog disiplininden ve doğru tarihten yoksun

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | changelog-hygiene |
| **Konum** | [CHANGELOG.md:1](../../../CHANGELOG.md#L1) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```markdown
CHANGELOG.md:1-3 ne "Keep a Changelog" ne de Semantic Versioning'e referans veriyor (standart başlık bloğu: "The format is based on Keep a Changelog... and this project adheres to Semantic Versioning"). 2.0.0 girdisinde yalnızca `### Added`, `### Changed`, `### Security` ve standart olmayan bir `### Not supported in this release` var; `Fixed`, `Removed` ve `Deprecated` bölümleri yok — oysa PR birçok davranışı kaldırıyor (VBANK/SSG_BANK/GIFT_CULT yeni ödemeleri, `id`siz shortcode, shortcode ticari override'ları) ve düzeltiyor. `### Security` altındaki satır 43 "Removed fresh-schema PAN storage" aslında bir `Removed` kalemi.
Tarih tutarsız: `## [2.0.0] - 2026-08-20` (satır 5) ama içerik daha sonra üretilmiş — languages/nicepay-payment-gateway.pot POT-Creation-Date: 2026-08-25, `NicePay_Transaction_Schema::VERSION = '2026.08.24.7'`, docs/ARCHITECTURE.md:459 aynı sürümü hedef gösteriyor.
Satır 56-58 tüm 1.x geçmişini "See Git history for details." ile kapatıyor.
`.github/scripts/check-version.js:97-111` yalnızca bölümün BOŞ olmadığını doğruluyor; format/bölüm disiplini için kapı yok.
```

**Başarısızlık senaryosu**

Bir satıcı, güncellemenin kapsamını değerlendirmek için CHANGELOG'u okur. `Removed` bölümü olmadığı için VBANK'ın yeni ödemelere kapatıldığını yalnızca `Changed` bölümündeki 27. satırın ortasında fark eder ("`VBANK`, `SSG_BANK`, and `GIFT_CULT` remain blocked") — "remain" kelimesi bunun zaten var olan bir kısıt olduğunu ima ettiği için, VBANK kullanan mağazasının güncelleme sonrası duracağını anlamaz.

**Etki**

Satıcı ve entegratör için sürümler arası fark okunabilirliği düşüyor; hangi davranışların kaldırıldığı `Changed` içine gömülü kaldığı için gözden kaçıyor (bkz. DOC-002). Yanlış yayın tarihi, denetim/uyum kaydı olarak kullanılabilecek dokümanın güvenilirliğini zayıflatıyor.

**Öneri**

CHANGELOG.md başlığına standart Keep a Changelog + SemVer cümlesini ekleyin; 2.0.0 girdisini `Added / Changed / Deprecated / Removed / Fixed / Security / Breaking changes` bölümlerine yeniden dağıtın (VBANK/SSG_BANK/GIFT_CULT yeni ödeme desteği, `id`siz shortcode ve shortcode ticari override'ları `Removed` + `Breaking changes` altına); `- 2026-08-20` tarihini gerçek yayın tarihine güncelleyin; `## [Unreleased]` bölümü açın. İsteğe bağlı olarak check-version.js'e 2.0.0 bölümünün en az bir tanınan alt başlık içerdiğini doğrulayan basit bir kontrol ekleyin.

---

## Düşmanca doğrulamada elenen iddialar

Yok — bu boyutta hiçbir iddia tümüyle çürütülmedi.

## Kapsam

İncelenen (tam okuma): README.md (216 satır), readme.txt (103), CHANGELOG.md (58), SECURITY.md (20), CONTRIBUTING.md (306), CODE_OF_CONDUCT.md (36), docs/API-REFERENCE.md (420), docs/ARCHITECTURE.md (461), docs/CONFIGURATION.md (497), docs/DEVELOPER-GUIDE.md (508), docs/USER-GUIDE.md (667), docs/WORDPRESS-ORG-RELEASE.md (207), .github/PULL_REQUEST_TEMPLATE.md, .github/ISSUE_TEMPLATE/bug_report.yml ve config.yml, LICENSE başlığı. Her iddia koda karşı doğrulandı: nicepay-payment-gateway.php (shortcode/aktivasyon/hook/endpoint), includes/nicepay-functions.php (durum etiketleri, log, alıcı doğrulama, presetler, makbuz, filtreler, save/update/get imzaları), includes/class-nicepay-api.php (imza/moid/HTTP/allowlist), includes/class-nicepay-transaction-schema.php (70 sütun, prepare_write), includes/class-nicepay-installer.php (duplicate blokları), admin/class-nicepay-admin.php (6 sekme, hazırlık paneli), admin/class-nicepay-transactions.php (CSV export), assets/js/nicepay.js + assets/css/nicepay.css (telefon regex, :has fallback), .github/workflows/deploy-wordpress-org.yml ve tests.yml, .github/scripts/check-version.js ve build-release.sh, composer.json/package.json, languages/*.pot başlığı, tests/integration/*.sh imaj pinleri. Kırıcı değişiklik iddiası `git show origin/main:nicepay-payment-gateway.php` ile eski shortcode handler'a karşı doğrulandı.\n\nİncelenmeyen / sınırlı: docs/analysis/00-13 (13 dosya, ~14.400 satır) yalnızca varlık/dil/indeks açısından değerlendirildi, içerik doğruluğu için tam okunmadı — görev kapsamındaki doküman listesinde yer almıyor (yalnız 14-remediation-progress.md okundu). Resmî WordPress.org readme validator ve otomatik markdown link-checker çalıştırılamadı (ağ erişimi yok); markdown link/anchor kontrolü elle örnekleme ile yapıldı (README→docs bağlantıları ve API-REFERENCE/USER-GUIDE ToC anchor'ları geçerli bulundu). .po/.mo katalog içerikleri (çeviri kalitesi) i18n boyutuna ait olduğu için incelenmedi; yalnızca CONFIGURATION.md'nin katalog listesi ile dosya sistemi karşılaştırıldı. \"Tested up to: 7.0\" değerinin gerçek WordPress sürüm takvimine uygunluğu dış kaynak gerektirdiği için doğrulanamadı; bulgu repo-içi kanıt eksikliği üzerinden yazıldı.

**Açık sorular**

- readme.txt'deki `Tested up to: 7.0` gerçekten test edilmiş bir WordPress sürümü mü, yoksa ileriye dönük bir tahmin mi? tests/integration/*.sh içindeki WordPress imaj digest'i hangi sürüme karşılık geliyor (yorum satırı yok)?
- `nicepay_db_version` opsiyonu (nicepay-payment-gateway.php:283) ileride bir migrasyon için bilinçli olarak mı bırakıldı, yoksa ölü kod mu? ARCHITECTURE.md:457'de "Database schema version" olarak belgelenmesi kalmalı mı?
- docs/analysis/ altındaki 14 dosyanın herkese açık repoda kalıcı olması bilinçli bir karar mı? Öyleyse güncel tutulacak mı, yoksa donmuş bir denetim anlık görüntüsü olarak mı işaretlenecek?
- Shortcode `buyer_name`/`buyer_email`/`buyer_tel` attribute'larının yok sayılması bilinçli bir güvenlik kararı mı (bu durumda dokümanlar düzeltilmeli), yoksa ticari alanlarla birlikte yanlışlıkla ezilmiş bir regresyon mu (bu durumda kod düzeltilmeli)?
- İstemci telefon doğrulaması (7-30 karakter) ile sunucu doğrulaması (7-20 karakter) arasındaki fark bilinçli mi? Doküman hangisini tek doğru kural olarak anlatmalı?
- Yayın ZIP'inin CONTRIBUTING.md ve CODE_OF_CONDUCT.md içermesi WordPress.org gönderimi için istenen bir durum mu, yoksa build-release.sh'den çıkarılmalı mı?

## Konum doğrulama durumu

Bulgu konumları dosya varlığı ve satır aralığı için otomatik kontrol edildi.

| Durum | Adet |
|---|---|
| ok | 19 |

## Öncelikli aksiyon listesi

1. **DOC-001** — 1) readme.txt başlık bloğuna onaylı WordPress.org kullanıcı adını ekleyin (tahmini GitHub adı değil):
```
=== NicePay Payment Gateway ===
Contributors: <onayli-wporg-kullanici-adi>
Tags: woocommerce, payment gateway, credit card, bank transfer, mobile payments
...
```
2) `== Screenshots ==` bölümü ekleyin veya en azından "görsel yok" durumunu readme'de değil sadece yayın rehberinde bırakmak yerine
2. **DOC-002** — 1) CHANGELOG.md'ye 2.0.0 altında ayrı bir `### Breaking changes` bölümü ekleyin ve en az şunları listeleyin: `[nicepay_payment]` artık kayıtlı `id` zorunlu kılıyor; `amount`/`goods_name`/`currency`/`pay_method`/`buyer_*` attribute'ları artık yok sayılıyor; standalone formlar yükseltmeden sonra kapalı gelir ve NicePay > Settings > Payment Methods'tan yeniden açılmalıdır; VBANK/SSG_BANK/GIFT_CULT ye
3. **DOC-003** — Ya davranışı ya da dokümanı hizalayın. Doküman tarafı için: README.md:106-108 ve docs/USER-GUIDE.md:430-432 satırlarındaki üç buyer satırını `Ignored; buyer contact values always come from the saved configuration` olarak değiştirin; README.md:115 ve 117 ile docs/USER-GUIDE.md:439'daki sunucu-otoritesi cümlesine `buyer_name`, `buyer_email`, `buyer_tel` alanlarını da dahil edin; docs/USER-GUIDE.md:4
4. **DOC-004** — ARCHITECTURE.md'yi bu PR'ın gerçek hâline göre yeniden üretin: (a) dizin ağacına 7 yeni sınıfı, blocks/shortcode-admin JS'lerini ve readme.txt'yi ekleyin; (b) class diyagramına NicePay_Installer, NicePay_Transaction_Schema, NicePay_Inbound_Validator, NicePay_Offer_Resolver, NicePay_Retention, NicePay_Privacy, NicePay_Blocks_Integration'ı ve bunların gateway/return-handler ile ilişkilerini ekleyin;
