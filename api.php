<?php

declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const SHEET_COLUMNS = [
    'envanter' => ['id','kod','ad','kategori','altkat','birim','konum','min','max','stok','kritiklik','tedarikci','sonAlim','fiyat','model','garanti','kondisyon','notlar','barkod','teknikBina','sorumluKisi'],
    'hareketler' => ['id','tarih','belge','malKod','malAd','tur','miktar','birim','personel','onaylayan','istasyon','notlar','teknikBina','alanKisi','depo','raf','konum'],
    'serino' => ['id','malKod','malAd','seriNo','durum','konum','giris','sonHareket','sorumlu','isEmri','bakimSayisi','versiyon','notlar','teknikBina','alanKisi','barkod'],
    'arizali' => ['id','malKod','malAd','seriNo','arizaTarih','gonderimTarih','tahminiDonus','aciklama','istasyon','arizaNo','tamirMerkezi','karar','kararTarih','notlar','fotograflar','videolar'],
    'kalibrasyon' => ['id','malKod','malAd','seriNo','bakimTur','periyot','sonBakim','sorumlu','notlar'],
    'kullanicilar' => ['id','user','pass','name','role','access','active','lastLogin'],
    'log' => ['id','tarih','kullanici','modul','islem','detay'],
    'personelFormu' => ['id','ad','soyad','sicilNo','unvan','birim','telefon','eposta','gorev','notlar'],
    'ayarlar' => ['anahtar','deger'],
];

const NUMBER_FIELDS = ['id','min','max','stok','miktar','fiyat','periyot','bakimSayisi'];
const BOOL_FIELDS = ['active'];
const JSONISH_FIELDS = ['fotograflar','videolar'];
const FILE_NAME = 'tcdd_depo_data.xml';

$action = $_GET['action'] ?? $_POST['action'] ?? 'bootstrap';
$storageDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
$filePath = $storageDir . DIRECTORY_SEPARATOR . FILE_NAME;

try {
    $dbUrl = (string)(getenv('DATABASE_URL') ?: '');

    if ($dbUrl !== '') {
        $store = new PostgresStore($dbUrl);
    } else {
        if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            throw new RuntimeException('storage klasoru olusturulamadi');
        }
        $store = new ExcelXmlStore($filePath);
    }

    switch ($action) {
        case 'bootstrap':
            jsonResponse(['success' => true, 'db' => $store->loadOrCreate()]);
            break;

        case 'sync':
            $payload = readJson();
            if (!isset($payload['db']) || !is_array($payload['db'])) {
                jsonResponse(['success' => false, 'error' => 'db payload zorunlu'], 422);
            }
            $store->save(normalizeDb($payload['db']));
            jsonResponse(['success' => true, 'message' => 'Excel XML senkronizasyon tamamlandi']);
            break;

        case 'login':
            $payload = readJson();
            $username = trim((string)($payload['username'] ?? ''));
            $password = trim((string)($payload['password'] ?? ''));
            if ($username === '' || $password === '') {
                jsonResponse(['success' => false, 'error' => 'Kullanici adi ve sifre zorunlu'], 422);
            }
            $db = $store->loadOrCreate();
            foreach (($db['kullanicilar'] ?? []) as $idx => $user) {
                $active = ($user['active'] ?? false) === true || ($user['active'] ?? '') === 'true';
                if (($user['user'] ?? '') === $username && password_verify($password, (string)($user['pass'] ?? '')) && $active) {
                    $db['kullanicilar'][$idx]['lastLogin'] = date('d.m.Y H:i');
                    $store->save($db);
                    jsonResponse([
                        'success' => true,
                        'user' => [
                            'id' => $user['id'],
                            'user' => $user['user'],
                            'name' => $user['name'],
                            'role' => $user['role'],
                            'access' => $user['access'],
                            'active' => true,
                            'lastLogin' => $db['kullanicilar'][$idx]['lastLogin'],
                        ],
                    ]);
                }
            }
            jsonResponse(['success' => false, 'error' => 'Hatali kullanici adi veya sifre'], 401);
            break;

        case 'download_excel':
        case 'download_xml':
            $db = $store->loadOrCreate();
            $xml = (new ExcelXmlStore($filePath))->exportToXml($db);
            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: attachment; filename="tcdd_depo_data.xml"');
            header('Content-Length: ' . (string)strlen($xml));
            echo $xml;
            exit;

        case 'upload_excel':
        case 'upload_xml':
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                jsonResponse(['success' => false, 'error' => 'Yalnizca POST desteklenir'], 405);
            }
            if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                jsonResponse(['success' => false, 'error' => 'Yuklenecek dosya bulunamadi'], 400);
            }
            $file = $_FILES['file'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                jsonResponse(['success' => false, 'error' => uploadErrorMessage((int)($file['error'] ?? UPLOAD_ERR_NO_FILE))], 400);
            }
            $tmp = (string)($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                jsonResponse(['success' => false, 'error' => 'Gecersiz yukleme'], 400);
            }
            $content = file_get_contents($tmp);
            if ($content === false || trim($content) === '') {
                jsonResponse(['success' => false, 'error' => 'XML dosyasi okunamadi'], 400);
            }
            if (stripos((string)($file['name'] ?? ''), '.xml') === false) {
                jsonResponse(['success' => false, 'error' => 'Lutfen .xml uzantili bir yedek secin'], 400);
            }
            if (stripos($content, '<Workbook') === false || stripos($content, '<Worksheet') === false) {
                jsonResponse(['success' => false, 'error' => 'Gecersiz XML yedek dosyasi'], 400);
            }
            // XML'i geçici dosya üzerinden parse et — hem DB hem dosya modunda çalışır
            $tmpXmlPath = tempnam(sys_get_temp_dir(), 'tcdd_') . '.xml';
            file_put_contents($tmpXmlPath, $content);
            try {
                $parsedDb = (new ExcelXmlStore($tmpXmlPath))->load();
            } finally {
                @unlink($tmpXmlPath);
            }
            $store->save(normalizeDb($parsedDb));
            jsonResponse(['success' => true, 'message' => 'XML yedek basariyla yuklendi']);
            break;

        case 'health':
            jsonResponse([
                'success' => true,
                'message' => 'API calisiyor',
                'storage' => ($store instanceof PostgresStore) ? 'PostgreSQL (Neon.tech)' : 'XML Dosyası',
                'storage_dir' => ($store instanceof PostgresStore) ? null : $storageDir,
                'excel_file' => ($store instanceof PostgresStore) ? null : $filePath,
                'excel_exists' => ($store instanceof PostgresStore) ? null : is_file($filePath),
                'storage_writable' => ($store instanceof PostgresStore) ? null : is_writable($storageDir),
            ]);
            break;

        default:
            jsonResponse(['success' => false, 'error' => 'Gecersiz action'], 400);
    }
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readJson(): array {
    $raw = file_get_contents('php://input') ?: '{}';
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function uploadErrorMessage(int $code): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Yuklenen dosya boyutu limiti asiyor',
        UPLOAD_ERR_PARTIAL => 'Dosya kismen yuklendi',
        UPLOAD_ERR_NO_FILE => 'Dosya secilmedi',
        UPLOAD_ERR_NO_TMP_DIR => 'Gecici klasor bulunamadi',
        UPLOAD_ERR_CANT_WRITE => 'Sunucu dosyayi diske yazamadi',
        UPLOAD_ERR_EXTENSION => 'Yukleme bir PHP eklentisi tarafindan durduruldu',
        default => 'Bilinmeyen yukleme hatasi',
    };
}

function normalizeDb(array $db): array {
    $base = defaultDb();
    foreach ($base as $sheet => $value) {
        if ($sheet === 'ayarlar') {
            if (isset($db['ayarlar']) && is_array($db['ayarlar'])) {
                $base['ayarlar'] = $db['ayarlar'];
            }
            continue;
        }
        if (isset($db[$sheet]) && is_array($db[$sheet])) {
            $base[$sheet] = array_values($db[$sheet]);
        }
    }
    return $base;
}

function defaultDb(): array {
    return [
        'envanter' => [
            ['id'=>1,'kod'=>'SIG-EL-0001','ad'=>'SM Kartı – Siemens SIMIS','kategori'=>'Sinyalizasyon','altkat'=>'Elektronik Kart','birim'=>'Adet','konum'=>'Ön Depo – A1 – Raf 2','min'=>2,'max'=>10,'stok'=>4,'kritiklik'=>'A','tedarikci'=>'Siemens TR','sonAlim'=>'2024-03-15','fiyat'=>45000,'model'=>'Siemens SIMIS-D SM v4.2','garanti'=>'2026-12-31','kondisyon'=>'Sıfır','notlar'=>'','barkod'=>'SIG-EL-0001','teknikBina'=>'DEPO','sorumluKisi'=>'Ahmet Yılmaz'],
            ['id'=>2,'kod'=>'SIG-EL-0002','ad'=>'PM Kartı – Siemens SIMIS','kategori'=>'Sinyalizasyon','altkat'=>'Elektronik Kart','birim'=>'Adet','konum'=>'Ön Depo – A1 – Raf 3','min'=>2,'max'=>8,'stok'=>4,'kritiklik'=>'A','tedarikci'=>'Siemens TR','sonAlim'=>'2024-03-20','fiyat'=>38000,'model'=>'Siemens SIMIS-D PM v3.1','garanti'=>'2026-12-31','kondisyon'=>'Sıfır','notlar'=>'','barkod'=>'SIG-EL-0002','teknikBina'=>'DEPO','sorumluKisi'=>'Ahmet Yılmaz'],
            ['id'=>3,'kod'=>'SIG-EL-0003','ad'=>'CPU Kartı – Alstom EBI','kategori'=>'Sinyalizasyon','altkat'=>'Elektronik Kart','birim'=>'Adet','konum'=>'Ön Depo – A2 – Raf 1','min'=>1,'max'=>5,'stok'=>0,'kritiklik'=>'A','tedarikci'=>'Alstom TR','sonAlim'=>'2024-01-10','fiyat'=>62000,'model'=>'Alstom EBI Gate 2000','garanti'=>'2027-06-30','kondisyon'=>'Sıfır','notlar'=>'Kritik','barkod'=>'SIG-EL-0003','teknikBina'=>'DEPO','sorumluKisi'=>'Mehmet Kaya'],
            ['id'=>4,'kod'=>'SIG-RL-0001','ad'=>'Ray Devresi – TI21','kategori'=>'Sinyalizasyon','altkat'=>'Ray Devresi','birim'=>'Adet','konum'=>'Ön Depo – A3 – Raf 1','min'=>2,'max'=>6,'stok'=>2,'kritiklik'=>'A','tedarikci'=>'Frauscher','sonAlim'=>'2024-02-05','fiyat'=>28500,'model'=>'Frauscher TI21 / 83Hz','garanti'=>'','kondisyon'=>'Revizyonlu','notlar'=>'','barkod'=>'SIG-RL-0001','teknikBina'=>'DEPO','sorumluKisi'=>'Ali Demir'],
            ['id'=>5,'kod'=>'HAB-FK-0001','ad'=>'Fiber Optik Kablo 48 Çekirdek','kategori'=>'Haberleşme','altkat'=>'Kablo','birim'=>'Metre','konum'=>'Arka Depo – B2 – Raf 1','min'=>50,'max'=>500,'stok'=>150,'kritiklik'=>'B','tedarikci'=>'Corning TR','sonAlim'=>'2024-03-08','fiyat'=>12.5,'model'=>'Corning SMF-28 Ultra','garanti'=>'','kondisyon'=>'Sıfır','notlar'=>'','barkod'=>'HAB-FK-0001','teknikBina'=>'DEPO','sorumluKisi'=>'Ali Demir'],
            ['id'=>6,'kod'=>'HAB-SW-0001','ad'=>'Ethernet Switch 8-Port','kategori'=>'Haberleşme','altkat'=>'Network','birim'=>'Adet','konum'=>'Ön Depo – A4 – Raf 2','min'=>2,'max'=>6,'stok'=>1,'kritiklik'=>'A','tedarikci'=>'Hirschmann','sonAlim'=>'2024-01-20','fiyat'=>8500,'model'=>'Hirschmann RS20-0800','garanti'=>'2025-12-31','kondisyon'=>'Sıfır','notlar'=>'','barkod'=>'HAB-SW-0001','teknikBina'=>'DEPO','sorumluKisi'=>'Hasan Öz'],
            ['id'=>7,'kod'=>'ENR-UPS-0001','ad'=>'UPS – 3kVA','kategori'=>'Enerji','altkat'=>'UPS','birim'=>'Adet','konum'=>'Arka Depo – C1 – Raf 1','min'=>1,'max'=>3,'stok'=>2,'kritiklik'=>'A','tedarikci'=>'Eaton TR','sonAlim'=>'2023-12-15','fiyat'=>25000,'model'=>'Eaton 5PX 3000i','garanti'=>'2026-12-15','kondisyon'=>'Sıfır','notlar'=>'','barkod'=>'ENR-UPS-0001','teknikBina'=>'DEPO','sorumluKisi'=>'Mehmet Kaya'],
            ['id'=>8,'kod'=>'MEK-MM-0001','ad'=>'Makas Motoru','kategori'=>'Mekanik','altkat'=>'Motor','birim'=>'Adet','konum'=>'Arka Depo – D1 – Raf 1','min'=>1,'max'=>4,'stok'=>2,'kritiklik'=>'A','tedarikci'=>'Siemens TR','sonAlim'=>'2023-11-10','fiyat'=>35000,'model'=>'Siemens 3SB HKT','garanti'=>'2026-11-10','kondisyon'=>'Sıfır','notlar'=>'','barkod'=>'MEK-MM-0001','teknikBina'=>'DEPO','sorumluKisi'=>'Mehmet Kaya'],
            ['id'=>9,'kod'=>'OLC-MT-0001','ad'=>'Multimetre Fluke 87V','kategori'=>'Ölçü Aleti','altkat'=>'Test/Ölçü','birim'=>'Adet','konum'=>'Ön Depo – A5 – Raf 1','min'=>1,'max'=>3,'stok'=>2,'kritiklik'=>'C','tedarikci'=>'Fluke TR','sonAlim'=>'2024-01-15','fiyat'=>12000,'model'=>'Fluke 87V','garanti'=>'2026-01-15','kondisyon'=>'Sıfır','notlar'=>'Kalibrasyonlu','barkod'=>'OLC-MT-0001','teknikBina'=>'DEPO','sorumluKisi'=>'Ahmet Yılmaz'],
        ],
        'hareketler' => [
            ['id'=>1,'tarih'=>'2024-03-01','belge'=>'GRS-2024-001','malKod'=>'SIG-EL-0001','malAd'=>'SM Kartı – Siemens SIMIS','tur'=>'Giriş','miktar'=>5,'birim'=>'Adet','personel'=>'Ahmet Yılmaz','onaylayan'=>'Müdür','istasyon'=>'Ön Depo','notlar'=>'FT-2024-0312','teknikBina'=>'DEPO','alanKisi'=>'Ahmet Yılmaz'],
            ['id'=>2,'tarih'=>'2024-03-10','belge'=>'CKS-2024-001','malKod'=>'SIG-EL-0001','malAd'=>'SM Kartı – Siemens SIMIS','tur'=>'Çıkış','miktar'=>1,'birim'=>'Adet','personel'=>'Mehmet Kaya','onaylayan'=>'Ahmet Yılmaz','istasyon'=>'Tavşanlı İst. – 4. Makas','notlar'=>'ARZ-2024-018','teknikBina'=>'EMS014','alanKisi'=>'Mehmet Kaya'],
            ['id'=>3,'tarih'=>'2024-03-12','belge'=>'GRS-2024-003','malKod'=>'HAB-FK-0001','malAd'=>'Fiber Optik Kablo 48 Çekirdek','tur'=>'Giriş','miktar'=>200,'birim'=>'Metre','personel'=>'Ali Demir','onaylayan'=>'Müdür','istasyon'=>'Arka Depo','notlar'=>'','teknikBina'=>'DEPO','alanKisi'=>'Ali Demir'],
        ],
        'serino' => [
            ['id'=>1,'malKod'=>'SIG-EL-0001','malAd'=>'SM Kartı – Siemens','seriNo'=>'SM-2024-001','durum'=>'Depoda','konum'=>'Ön Depo – A1 – Raf 2','giris'=>'2024-03-01','sonHareket'=>'','sorumlu'=>'Ahmet Yılmaz','isEmri'=>'','bakimSayisi'=>0,'versiyon'=>'HW:4.2 / SW:3.1','notlar'=>'','teknikBina'=>'DEPO','alanKisi'=>'Ahmet Yılmaz','barkod'=>'SM-2024-001'],
            ['id'=>2,'malKod'=>'SIG-EL-0001','malAd'=>'SM Kartı – Siemens','seriNo'=>'SM-2023-047','durum'=>'Sahada','konum'=>'EMS014 Teknik Binası','giris'=>'2023-06-15','sonHareket'=>'2024-03-10','sorumlu'=>'Mehmet Kaya','isEmri'=>'ARZ-2024-018','bakimSayisi'=>2,'versiyon'=>'HW:4.1 / SW:2.8','notlar'=>'Sahada kullanımda','teknikBina'=>'EMS014','alanKisi'=>'Mehmet Kaya','barkod'=>'SM-2023-047'],
            ['id'=>3,'malKod'=>'SIG-RL-0001','malAd'=>'Ray Devresi TI21','seriNo'=>'TI21-2024-001','durum'=>'Depoda','konum'=>'Ön Depo – A3 – Raf 1','giris'=>'2024-03-20','sonHareket'=>'','sorumlu'=>'Ali Demir','isEmri'=>'','bakimSayisi'=>0,'versiyon'=>'TI21 / 83Hz','notlar'=>'','teknikBina'=>'DEPO','alanKisi'=>'Ali Demir','barkod'=>'TI21-2024-001'],
        ],
        'arizali' => [
            ['id'=>1,'malKod'=>'SIG-EL-0001','malAd'=>'SM Kartı – Siemens','seriNo'=>'SM-2023-047','arizaTarih'=>'2024-03-08','gonderimTarih'=>'2024-03-10','tahminiDonus'=>'2024-04-15','aciklama'=>'Çıkış kapısı arızası','istasyon'=>'Tavşanlı İst.','arizaNo'=>'ARZ-2024-018','tamirMerkezi'=>'Siemens TR Servis','karar'=>'Tamirde','kararTarih'=>'','notlar'=>'Garanti kapsamında','fotograflar'=>[],'videolar'=>[]],
        ],
        'kalibrasyon' => [
            ['id'=>1,'malKod'=>'OLC-MT-0001','malAd'=>'Multimetre Fluke 87V','seriNo'=>'FLK-2024-001','bakimTur'=>'Kalibrasyon','periyot'=>12,'sonBakim'=>'2024-01-15','sorumlu'=>'Ahmet Yılmaz','notlar'=>'TSE onaylı'],
            ['id'=>2,'malKod'=>'ENR-UPS-0001','malAd'=>'UPS – 3kVA','seriNo'=>'UPS-2024-001','bakimTur'=>'Akü Testi','periyot'=>6,'sonBakim'=>'2024-02-01','sorumlu'=>'Mehmet Kaya','notlar'=>''],
            ['id'=>3,'malKod'=>'MEK-MM-0001','malAd'=>'Makas Motoru','seriNo'=>'MM-2024-001','bakimTur'=>'Mekanik Bakım','periyot'=>6,'sonBakim'=>'2024-01-20','sorumlu'=>'Mehmet Kaya','notlar'=>'Yağlama kontrolü'],
        ],
        'kullanicilar' => [
            ['id'=>1,'user'=>'admin','pass'=>password_hash('admin123',PASSWORD_DEFAULT),'name'=>'Sistem Yöneticisi','role'=>'Yönetici','access'=>'Tüm Depolar','active'=>true,'lastLogin'=>''],
            ['id'=>2,'user'=>'depo1','pass'=>password_hash('depo123',PASSWORD_DEFAULT),'name'=>'Ahmet Yılmaz','role'=>'Depo Sorumlusu','access'=>'Ön Depo, Arka Depo','active'=>true,'lastLogin'=>''],
            ['id'=>3,'user'=>'gorevli1','pass'=>password_hash('gorev123',PASSWORD_DEFAULT),'name'=>'Ali Demir','role'=>'Görevli','access'=>'Tüm Depolar','active'=>true,'lastLogin'=>''],
            ['id'=>4,'user'=>'izleyici','pass'=>password_hash('izle123',PASSWORD_DEFAULT),'name'=>'Hasan Öz','role'=>'İzleyici','access'=>'Tüm Depolar (Salt Okunur)','active'=>true,'lastLogin'=>''],
        ],
        'log' => [],
        'personelFormu' => [
            ['id'=>1,'ad'=>'Ahmet','soyad'=>'Yılmaz','sicilNo'=>'TVS-001','unvan'=>'Depo Sorumlusu','birim'=>'SH Bakım','telefon'=>'05XX-XXX-XXXX','eposta'=>'ahmet@tcdd.gov.tr','gorev'=>'Depo sorumlusu','notlar'=>''],
            ['id'=>2,'ad'=>'Mehmet','soyad'=>'Kaya','sicilNo'=>'TVS-002','unvan'=>'Tekniker','birim'=>'SH Bakım','telefon'=>'05XX-XXX-XXXX','eposta'=>'mehmet@tcdd.gov.tr','gorev'=>'Saha tekniker','notlar'=>''],
            ['id'=>3,'ad'=>'Ali','soyad'=>'Demir','sicilNo'=>'TVS-003','unvan'=>'Tekniker','birim'=>'SH Bakım','telefon'=>'05XX-XXX-XXXX','eposta'=>'ali@tcdd.gov.tr','gorev'=>'Saha tekniker','notlar'=>''],
        ],
        'ayarlar' => [
            'kategoriler' => ['Sinyalizasyon','Haberleşme','Enerji','Mekanik','Sarf Malzeme','Ölçü Aleti'],
            'birimler' => ['Adet','Metre','Kg','Takım','Kutu','Rulo','Litre','Paket'],
            'depolar' => ['Ön Depo','Arka Depo','Yan Alan','Ön Alan'],
            'islemturleri' => ['Giriş','Çıkış','Sayım Farkı','Hurda','Tamir Gönderildi','İade'],
            'arizatipler' => ['Elektronik Arıza','Mekanik Hasar','Yazılım Hatası','Fiziksel Hasar','Aşınma','Bilinmeyen'],
            'istasyonlar' => ['Tavşanlı Garı','Simav İstasyonu','Emet İstasyonu','Gediz İstasyonu','Kütahya İstasyonu','Afyonkarahisar'],
            'teknikBinalar' => ['EMS014','EM048','BA060','DEPO','GEÇİCİ'],
            'tedarikci' => [
                ['ad'=>'Siemens TR','kisi'=>'Teknik Destek','tel'=>'0212-XXX-XXXX','email'=>'teknik@siemens.com.tr','tip'=>'Elektronik Kart'],
                ['ad'=>'Alstom TR','kisi'=>'Servis','tel'=>'0312-XXX-XXXX','email'=>'servis@alstom.com','tip'=>'Sinyal Sistemi'],
                ['ad'=>'Frauscher','kisi'=>'Destek','tel'=>'0232-XXX-XXXX','email'=>'support@frauscher.com','tip'=>'Ray Devresi'],
                ['ad'=>'Hirschmann','kisi'=>'Servis','tel'=>'0216-XXX-XXXX','email'=>'info@hirschmann.com','tip'=>'Network Ürünleri'],
            ],
            'depoYapi' => [
                ['ad'=>'Ön Depo','raflar'=>['A1','A2','A3','A4','A5'],'icon'=>'⚡'],
                ['ad'=>'Arka Depo','raflar'=>['B1','B2','B3','B4','C1','C2','C3'],'icon'=>'🔌'],
                ['ad'=>'Yan Alan','raflar'=>['D1','D2','D3'],'icon'=>'⚙️'],
            ],
        ],
    ];
}

final class ExcelXmlStore {
    private string $path;

    public function __construct(string $path) {
        $this->path = $path;
    }

    public function loadOrCreate(): array {
        if (!is_file($this->path)) {
            $db = defaultDb();
            $this->save($db);
            return $db;
        }
        try {
            return $this->load();
        } catch (Throwable $e) {
            @rename($this->path, $this->path . '.broken-' . date('Ymd-His'));
            $db = defaultDb();
            $this->save($db);
            return $db;
        }
    }

    public function load(): array {
        $xml = file_get_contents($this->path);
        if ($xml === false || trim($xml) === '') {
            throw new RuntimeException('Excel XML dosyasi okunamadi');
        }
        $db = defaultDb();
        preg_match_all('/<Worksheet[^>]*ss:Name="([^"]+)"[^>]*>(.*?)<\/Worksheet>/su', $xml, $worksheets, PREG_SET_ORDER);
        foreach ($worksheets as $worksheet) {
            $sheetName = $worksheet[1];
            if (!isset(SHEET_COLUMNS[$sheetName])) {
                continue;
            }
            $sheetXml = $worksheet[2];
            preg_match_all('/<Row[^>]*>(.*?)<\/Row>/su', $sheetXml, $rowMatches, PREG_SET_ORDER);
            $rows = [];
            foreach ($rowMatches as $rowMatch) {
                preg_match_all('/<Cell[^>]*>(.*?)<\/Cell>/su', $rowMatch[1], $cellMatches, PREG_SET_ORDER);
                $row = [];
                foreach ($cellMatches as $cellMatch) {
                    if (preg_match('/<Data[^>]*>(.*?)<\/Data>/su', $cellMatch[1], $dataMatch)) {
                        $row[] = html_entity_decode($dataMatch[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                    } else {
                        $row[] = '';
                    }
                }
                $rows[] = $row;
            }
            if ($sheetName === 'ayarlar') {
                $settings = [];
                foreach ($rows as $i => $row) {
                    if ($i === 0) continue;
                    $key = trim((string)($row[0] ?? ''));
                    $raw = (string)($row[1] ?? '');
                    if ($key === '') continue;
                    $decoded = json_decode($raw, true);
                    $settings[$key] = ($decoded === null && json_last_error() !== JSON_ERROR_NONE) ? $raw : $decoded;
                }
                if ($settings) $db['ayarlar'] = $settings;
                continue;
            }
            $headers = $rows[0] ?? [];
            $records = [];
            for ($r = 1; $r < count($rows); $r++) {
                $record = [];
                foreach ($headers as $c => $header) {
                    $header = trim((string)$header);
                    if ($header === '') continue;
                    $value = (string)($rows[$r][$c] ?? '');
                    if ($value === '') {
                        $record[$header] = in_array($header, NUMBER_FIELDS, true) ? 0 : '';
                    } elseif (in_array($header, NUMBER_FIELDS, true)) {
                        $record[$header] = is_numeric($value) ? $value + 0 : 0;
                    } elseif (in_array($header, BOOL_FIELDS, true)) {
                        $record[$header] = in_array(strtolower($value), ['1','true','yes'], true);
                    } elseif (in_array($header, JSONISH_FIELDS, true)) {
                        $decoded = json_decode($value, true);
                        $record[$header] = ($decoded === null && json_last_error() !== JSON_ERROR_NONE) ? $value : $decoded;
                    } else {
                        $decoded = null;
                        if (($value[0] ?? '') === '[' || ($value[0] ?? '') === '{') {
                            $decoded = json_decode($value, true);
                        }
                        $record[$header] = ($decoded === null && json_last_error() !== JSON_ERROR_NONE) ? $value : ($decoded ?? $value);
                    }
                }
                if (($record['id'] ?? 0) !== 0) $records[] = $record;
            }
            $db[$sheetName] = $records;
        }
        return $db;
    }

    public function save(array $db): void {
        $tmp = $this->path . '.tmp';
        $xml = $this->buildWorkbookXml($db);
        if (file_put_contents($tmp, $xml) === false) {
            throw new RuntimeException('Excel XML dosyasi yazilamadi');
        }
        if (!@rename($tmp, $this->path)) {
            @unlink($this->path);
            if (!@rename($tmp, $this->path)) {
                throw new RuntimeException('Excel XML dosyasi kaydedilemedi');
            }
        }
    }

    public function exportToXml(array $db): string {
        return $this->buildWorkbookXml($db);
    }

    public function buildWorkbookXml(array $db): string {
        $worksheets = '';
        foreach (SHEET_COLUMNS as $sheetName => $columns) {
            $rows = [];
            if ($sheetName === 'ayarlar') {
                $rows[] = $columns;
                foreach (($db['ayarlar'] ?? []) as $key => $value) {
                    $rows[] = [(string)$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
                }
            } else {
                $rows[] = $columns;
                foreach (($db[$sheetName] ?? []) as $record) {
                    $row = [];
                    foreach ($columns as $column) {
                        $value = $record[$column] ?? '';
                        if (is_bool($value)) {
                            $value = $value ? 'true' : 'false';
                        } elseif (is_array($value) || is_object($value)) {
                            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        }
                        $row[] = (string)$value;
                    }
                    $rows[] = $row;
                }
            }
            $worksheets .= '<Worksheet ss:Name="' . xmlEsc($sheetName) . '"><Table>';
            foreach ($rows as $row) {
                $worksheets .= '<Row>';
                foreach ($row as $cell) {
                    $worksheets .= '<Cell><Data ss:Type="String">' . xmlEsc((string)$cell) . '</Data></Cell>';
                }
                $worksheets .= '</Row>';
            }
            $worksheets .= '</Table></Worksheet>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<?mso-application progid="Excel.Sheet"?>'
            . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" xmlns:html="http://www.w3.org/TR/REC-html40">'
            . '<DocumentProperties xmlns="urn:schemas-microsoft-com:office:office"><Author>OpenAI</Author><Created>' . gmdate('Y-m-d\TH:i:s\Z') . '</Created></DocumentProperties>'
            . '<ExcelWorkbook xmlns="urn:schemas-microsoft-com:office:excel"><ProtectStructure>False</ProtectStructure><ProtectWindows>False</ProtectWindows></ExcelWorkbook>'
            . '<Styles><Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Bottom"/><Borders/><Font/><Interior/><NumberFormat/><Protection/></Style></Styles>'
            . $worksheets
            . '</Workbook>';
    }
}

function xmlEsc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// PostgresStore — Neon.tech / Render için veritabanı depolama katmanı
// ExcelXmlStore ile aynı public arayüzü sunar; DATABASE_URL varsa kullanılır.
// ─────────────────────────────────────────────────────────────────────────────
final class PostgresStore {
    private PDO $pdo;

    public function __construct(string $dsn) {
        // Neon.tech postgresql:// URL'sini PDO DSN'e çevir
        $p = parse_url($dsn);
        if ($p === false || !isset($p['host'], $p['path'])) {
            throw new RuntimeException('Geçersiz DATABASE_URL formatı');
        }
        $pdoDsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
            $p['host'],
            $p['port'] ?? 5432,
            ltrim($p['path'], '/')
        );
        $this->pdo = new PDO($pdoDsn, $p['user'] ?? '', $p['pass'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->initTable();
    }

    private function initTable(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS tcdd_storage (
                key        TEXT PRIMARY KEY,
                value      TEXT NOT NULL,
                updated_at TIMESTAMPTZ DEFAULT NOW()
            )
        ");
    }

    public function loadOrCreate(): array {
        try {
            return $this->load();
        } catch (Throwable) {
            $db = defaultDb();
            $this->save($db);
            return $db;
        }
    }

    public function load(): array {
        $stmt = $this->pdo->query("SELECT value FROM tcdd_storage WHERE key = 'main_db'");
        $row  = $stmt ? $stmt->fetch() : false;
        if (!$row) {
            throw new RuntimeException('Veritabanında kayıt bulunamadı');
        }
        $db = json_decode((string)$row['value'], true);
        if (!is_array($db)) {
            throw new RuntimeException('Veritabanı verisi bozuk');
        }
        return $db;
    }

    public function save(array $db): void {
        $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare("
            INSERT INTO tcdd_storage (key, value, updated_at)
            VALUES ('main_db', :v, NOW())
            ON CONFLICT (key) DO UPDATE SET value = :v, updated_at = NOW()
        ");
        $stmt->execute([':v' => $json]);
    }
}
