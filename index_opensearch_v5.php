<?php
/**
 * 🚀 ИНДЕКСАТОР OPENSEARCH v5.1 - ИСПРАВЛЕННАЯ ВЕРСИЯ
 * 
 * Индексирует статические данные товаров с поддержкой:
 * - Конвертации раскладки клавиатуры
 * - Умного поиска по артикулам
 * - Синонимов и транслитерации
 */

// 🔧 ПРАВКА 1: Правильная инициализация для CLI режима
require_once __DIR__ . '/vendor/autoload.php';

// Инициализируем только необходимые компоненты для CLI
try {
    // Проверяем, что мы в CLI режиме
    if (php_sapi_name() !== 'cli') {
        die("❌ Этот скрипт можно запускать только из командной строки\n");
    }
    
    // Загружаем конфигурацию напрямую
    if (!class_exists('\App\Core\Config')) {
        require_once __DIR__ . '/src/Core/Config.php';
    }
    \App\Core\Config::load();
    
    // Инициализируем только Database
    if (!class_exists('\App\Core\Database')) {
        require_once __DIR__ . '/src/Core/Database.php';
    }
    
    // Инициализируем Logger
    if (!class_exists('\App\Core\Logger')) {
        require_once __DIR__ . '/src/Core/Logger.php';
    }
    
} catch (\Exception $e) {
    die("❌ Ошибка инициализации: " . $e->getMessage() . "\n");
}

use OpenSearch\ClientBuilder;
use App\Core\Database;
use App\Core\Config;
use App\Core\Logger;

// 🔧 Конфигурация
const BATCH_SIZE = 1000;
const MEMORY_LIMIT = '4G';
const MAX_EXECUTION_TIME = 3600;
const MAX_OLD_INDICES = 2;

class StaticProductIndexer {
    private $client;
    private $pdo;
    private $processed = 0;
    private $errors = 0;
    private $startTime;
    public $newIndexName; // Сделано публичным для доступа в catch блоке
    private $totalProducts = 0;
    
    // Маппинг для конвертации раскладки
    private $keyboardLayout = [
        // EN -> RU
        'q' => 'й', 'w' => 'ц', 'e' => 'у', 'r' => 'к', 't' => 'е', 'y' => 'н', 'u' => 'г', 'i' => 'ш', 'o' => 'щ', 'p' => 'з',
        'a' => 'ф', 's' => 'ы', 'd' => 'в', 'f' => 'а', 'g' => 'п', 'h' => 'р', 'j' => 'о', 'k' => 'л', 'l' => 'д',
        'z' => 'я', 'x' => 'ч', 'c' => 'с', 'v' => 'м', 'b' => 'и', 'n' => 'т', 'm' => 'ь',
        // RU -> EN
        'й' => 'q', 'ц' => 'w', 'у' => 'e', 'к' => 'r', 'е' => 't', 'н' => 'y', 'г' => 'u', 'ш' => 'i', 'щ' => 'o', 'з' => 'p',
        'ф' => 'a', 'ы' => 's', 'в' => 'd', 'а' => 'f', 'п' => 'g', 'р' => 'h', 'о' => 'j', 'л' => 'k', 'д' => 'l',
        'я' => 'z', 'ч' => 'x', 'с' => 'c', 'м' => 'v', 'и' => 'b', 'т' => 'n', 'ь' => 'm'
    ];

    public function __construct() {
        $this->startTime = microtime(true);
        $this->newIndexName = 'products_' . date('Y_m_d_H_i_s');
        
        ini_set('memory_limit', MEMORY_LIMIT);
        ini_set('max_execution_time', MAX_EXECUTION_TIME);
        set_time_limit(0);
        
        echo $this->getHeader();
    }

    /**
     * 🎯 Главный метод запуска
     */
    public function run(): void {
        try {
            $this->initializeConnections();
            $this->analyzeCurrentState();
            $this->createNewIndex();
            $this->indexAllProducts();
            $this->switchAlias();
            $this->cleanupOldIndices();
            $this->showFinalReport();
        } catch (Throwable $e) {
            $this->handleError($e);
        }
    }

    /**
     * 🔌 Инициализация соединений
     * 🔧 ПРАВКА 2: Исправлено подключение к OpenSearch и БД
     */
    private function initializeConnections(): void {
        echo "🔌 === ИНИЦИАЛИЗАЦИЯ ===\n\n";
        
        // OpenSearch
        try {
            $this->client = ClientBuilder::create()
                ->setHosts(['localhost:9200'])
                ->setRetries(3)
                ->setConnectionParams([
                    'timeout' => 10,
                    'connect_timeout' => 5
                ])
                ->build();
                
            $info = $this->client->info();
            echo "✅ OpenSearch подключен: v" . $info['version']['number'] . "\n";
            
            $health = $this->client->cluster()->health();
            echo "📊 Статус кластера: {$health['status']}\n";
            
        } catch (\Exception $e) {
            throw new \Exception("❌ Ошибка OpenSearch: " . $e->getMessage());
        }

        // База данных - используем App\Core\Database
        try {
            $this->pdo = Database::getConnection();
            echo "✅ База данных подключена\n\n";
        } catch (\Exception $e) {
            throw new \Exception("❌ Ошибка БД: " . $e->getMessage());
        }
    }

    /**
     * 📊 Анализ текущего состояния
     */
    private function analyzeCurrentState(): void {
        echo "📊 === АНАЛИЗ ===\n\n";
        
        $this->totalProducts = $this->pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
        echo "📦 Товаров в БД: " . number_format($this->totalProducts) . "\n";
        
        $brands = $this->pdo->query("SELECT COUNT(DISTINCT brand_id) FROM products WHERE brand_id IS NOT NULL")->fetchColumn();
        $series = $this->pdo->query("SELECT COUNT(DISTINCT series_id) FROM products WHERE series_id IS NOT NULL")->fetchColumn();
        $categories = $this->pdo->query("SELECT COUNT(*) FROM product_categories")->fetchColumn();
        $images = $this->pdo->query("SELECT COUNT(DISTINCT product_id) FROM product_images")->fetchColumn();
        
        echo "🏷️ Брендов используется: " . number_format($brands) . "\n";
        echo "📚 Серий используется: " . number_format($series) . "\n";
        echo "📁 Связей с категориями: " . number_format($categories) . "\n";
        echo "🖼️ Товаров с изображениями: " . number_format($images) . "\n\n";
    }

    /**
     * 📝 Создание нового индекса
     */
    private function createNewIndex(): void {
        echo "📝 === СОЗДАНИЕ ИНДЕКСА ===\n\n";
        echo "🆕 Имя индекса: {$this->newIndexName}\n";
        
        // Загружаем конфигурацию
        $configFile = __DIR__ . '/opensearch_mappings/products_v5.json';
        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);
            echo "📄 Конфигурация загружена из файла\n";
        } else {
            $config = $this->getIndexConfiguration();
            // Сохраняем для будущего использования
            @mkdir(dirname($configFile), 0755, true);
            file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            echo "📄 Создан файл конфигурации: $configFile\n";
        }
        
        // Создаем индекс
        try {
            $this->client->indices()->create([
                'index' => $this->newIndexName,
                'body' => $config
            ]);
            echo "✅ Индекс создан успешно\n\n";
        } catch (Exception $e) {
            throw new Exception("❌ Ошибка создания индекса: " . $e->getMessage());
        }
    }

    /**
     * 📦 Индексация всех товаров
     */
    private function indexAllProducts(): void {
        echo "📦 === ИНДЕКСАЦИЯ ===\n\n";
        echo "🔄 Начинаем индексацию " . number_format($this->totalProducts) . " товаров...\n";
        echo "📊 Размер пакета: " . BATCH_SIZE . "\n\n";
        
        $offset = 0;
        $progressBar = $this->initProgressBar();
        
        while ($offset < $this->totalProducts) {
            $products = $this->fetchProductBatch($offset);
            if (empty($products)) break;
            
            $this->indexBatch($products);
            
            $offset += BATCH_SIZE;
            $this->updateProgress($offset);
            
            // Управление памятью
            if ($offset % 10000 === 0) {
                gc_collect_cycles();
            }
        }
        
        echo "\n✅ Индексация завершена!\n\n";
    }

    /**
     * 📥 Получение пакета товаров
     * 🔧 ПРАВКА 3: Исправлен биндинг параметров
     */
    private function fetchProductBatch(int $offset): array {
        $sql = "
            SELECT 
                p.product_id,
                p.external_id,
                p.sku,
                p.name,
                p.description,
                p.unit,
                p.min_sale,
                p.weight,
                p.dimensions,
                p.created_at,
                p.updated_at,
                p.brand_id,
                p.series_id,
                b.name as brand_name,
                s.name as series_name
            FROM products p
            LEFT JOIN brands b ON p.brand_id = b.brand_id
            LEFT JOIN series s ON p.series_id = s.series_id
            ORDER BY p.product_id
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', BATCH_SIZE, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * 📤 Индексация пакета
     * 🔧 ПРАВКА 5: Добавлена обработка ошибок и проверка памяти
     */
    private function indexBatch(array $products): void {
        $bulkData = [];
        
        foreach ($products as $product) {
            try {
                // Получаем дополнительные данные
                $product['categories'] = $this->getProductCategories($product['product_id']);
                $product['images'] = $this->getProductImages($product['product_id']);
                $product['attributes'] = $this->getProductAttributes($product['product_id']);
                $product['documents'] = $this->getProductDocuments($product['product_id']);
                
                // Подготавливаем документ для индексации
                $doc = $this->prepareDocument($product);
                
                // Добавляем в bulk
                $bulkData[] = ['index' => ['_index' => $this->newIndexName, '_id' => $product['product_id']]];
                $bulkData[] = $doc;
            } catch (\Exception $e) {
                Logger::error('Failed to prepare product', [
                    'product_id' => $product['product_id'],
                    'error' => $e->getMessage()
                ]);
                $this->errors++;
            }
        }
        
        // Отправляем в OpenSearch
        if (!empty($bulkData)) {
            try {
                $response = $this->client->bulk(['body' => $bulkData]);
                
                if (isset($response['errors']) && $response['errors'] === true) {
                    $this->handleBulkErrors($response['items']);
                } else {
                    $this->processed += count($products);
                }
                
                // Проверяем использование памяти
                $memoryUsage = memory_get_usage(true);
                $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
                
                if ($memoryUsage > $memoryLimit * 0.8) {
                    echo "\n⚠️ Предупреждение: использовано 80% памяти, запускаем сборщик мусора\n";
                    gc_collect_cycles();
                }
                
            } catch (\Exception $e) {
                $this->errors += count($products);
                Logger::error("Bulk indexing error", [
                    'error' => $e->getMessage(),
                    'batch_size' => count($products)
                ]);
                
                // Пробуем проиндексировать по одному
                echo "\n⚠️ Ошибка bulk операции, пробуем по одному документу...\n";
                $this->indexOneByOne($bulkData);
            }
        }
    }

    /**
     * 🔧 ПРАВКА 6: Новый метод для индексации по одному документу
     */
    private function indexOneByOne(array $bulkData): void {
        for ($i = 0; $i < count($bulkData); $i += 2) {
            $meta = $bulkData[$i];
            $doc = $bulkData[$i + 1];
            
            try {
                $this->client->index([
                    'index' => $meta['index']['_index'],
                    'id' => $meta['index']['_id'],
                    'body' => $doc
                ]);
                $this->processed++;
            } catch (\Exception $e) {
                $this->errors++;
                Logger::error('Single document indexing failed', [
                    'id' => $meta['index']['_id'],
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * 🔧 ПРАВКА 7: Метод для парсинга лимита памяти
     */
    private function parseMemoryLimit(string $memoryLimit): int {
        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int) $memoryLimit;
        
        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return $value;
        }
    }

    /**
     * 🔨 Подготовка документа для индексации
     */
    private function prepareDocument(array $product): array {
        // Числовые свойства для фасетного поиска
        $numericProps = [];
        if (!empty($product['attributes'])) {
            foreach ($product['attributes'] as $attr) {
                if (preg_match('/^([\d\.,]+)\s*([а-яА-ЯA-Za-z]*)/', $attr['value'], $matches)) {
                    $numericProps[] = [
                        'name' => $attr['name'],
                        'value' => (float) str_replace(',', '.', $matches[1]),
                        'unit' => $matches[2] ?: $attr['unit']
                    ];
                }
            }
        }
        
        // Добавляем варианты названия для поиска с конвертацией раскладки
        $searchVariants = $this->generateSearchVariants($product['name']);
        $searchText = $this->buildSearchText($product);
        
        // Добавляем варианты артикула
        if (!empty($product['external_id'])) {
            $searchVariants = array_merge($searchVariants, $this->generateSearchVariants($product['external_id']));
        }
        
        $doc = [
            'product_id' => (int)$product['product_id'],
            'external_id' => $this->normalizeText($product['external_id']),
            'sku' => $this->normalizeText($product['sku']),
            'name' => $product['name'],
            'description' => $this->normalizeText($product['description']),
            
            // Бренд и серия
            'brand_id' => $product['brand_id'] ? (int)$product['brand_id'] : null,
            'brand_name' => $this->normalizeText($product['brand_name']),
            'series_id' => $product['series_id'] ? (int)$product['series_id'] : null,
            'series_name' => $this->normalizeText($product['series_name']),
            
            // Категории
            'categories' => $product['categories']['names'] ?? [],
            'category_ids' => $product['categories']['ids'] ?? [],
            
            // Изображения и документы
            'images' => $product['images'],
            'documents' => $product['documents'],
            
            // Атрибуты
            'attributes' => $product['attributes'],
            'numeric_props' => $numericProps,
            
            // Характеристики
            'unit' => $product['unit'],
            'min_sale' => $product['min_sale'] ? (int)$product['min_sale'] : null,
            'weight' => $product['weight'] ? (float)$product['weight'] : null,
            'dimensions' => $product['dimensions'],
            
            // Метрики
            'popularity_score' => $this->getPopularityScore($product['product_id']),
            'total_stock' => $this->getTotalStock($product['product_id']),
            'cities_available' => $this->getCitiesWithStock($product['product_id']),
            'has_stock' => $this->hasAnyStock($product['product_id']),
            
            // Флаги
            'has_images' => !empty($product['images']),
            'has_description' => !empty($product['description']),
            
            // Поисковый текст с вариантами
            'search_text' => $searchText . ' ' . implode(' ', $searchVariants),
            'suggest' => $this->buildSuggestData($product),
            
            // Временные метки
            'created_at' => $this->formatDate($product['created_at']),
            'updated_at' => $this->formatDate($product['updated_at'])
        ];
        
        // Удаляем пустые значения
        return array_filter($doc, function($value) {
            return $value !== null && $value !== '' && $value !== [];
        });
    }

    /**
     * 🔤 Генерация вариантов для поиска
     */
    private function generateSearchVariants(string $text): array {
        $variants = [];
        
        // 1. Конвертация раскладки
        $converted = $this->convertKeyboardLayout($text);
        if ($converted !== $text) {
            $variants[] = $converted;
        }
        
        // 2. Транслитерация
        $transliterated = $this->transliterate($text);
        if ($transliterated !== $text) {
            $variants[] = $transliterated;
        }
        
        // 3. Без пробелов и дефисов
        $normalized = preg_replace('/[\s\-_]+/', '', $text);
        if ($normalized !== $text) {
            $variants[] = $normalized;
        }
        
        return array_unique($variants);
    }

    /**
     * ⌨️ Конвертация раскладки клавиатуры
     */
    private function convertKeyboardLayout(string $text): string {
        $result = '';
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        
        foreach ($chars as $char) {
            $lower = mb_strtolower($char);
            if (isset($this->keyboardLayout[$lower])) {
                $converted = $this->keyboardLayout[$lower];
                // Сохраняем регистр
                if ($char !== $lower) {
                    $converted = mb_strtoupper($converted);
                }
                $result .= $converted;
            } else {
                $result .= $char;
            }
        }
        
        return $result;
    }

    /**
     * 🔤 Транслитерация
     */
    private function transliterate(string $text): string {
        $rules = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya'
        ];

        $text = mb_strtolower($text);
        return strtr($text, $rules);
    }

    /**
     * 📁 Получение категорий товара
     */
    private function getProductCategories(int $productId): array {
        $stmt = $this->pdo->prepare("
            SELECT c.category_id, c.name
            FROM product_categories pc
            JOIN categories c ON pc.category_id = c.category_id
            WHERE pc.product_id = ?
        ");
        $stmt->execute([$productId]);
        
        $ids = [];
        $names = [];
        
        while ($row = $stmt->fetch()) {
            $ids[] = (int)$row['category_id'];
            $names[] = $row['name'];
        }
        
        return ['ids' => $ids, 'names' => $names];
    }

    /**
     * 🖼️ Получение изображений товара
     */
    private function getProductImages(int $productId): array {
        $stmt = $this->pdo->prepare("
            SELECT url, alt_text, is_main
            FROM product_images
            WHERE product_id = ?
            ORDER BY is_main DESC, sort_order ASC
        ");
        $stmt->execute([$productId]);
        
        $images = [];
        while ($row = $stmt->fetch()) {
            $images[] = $row['url'];
        }
        
        return $images;
    }

    /**
     * 📋 Получение атрибутов товара
     */
    private function getProductAttributes(int $productId): array {
        $stmt = $this->pdo->prepare("
            SELECT name, value, unit
            FROM product_attributes
            WHERE product_id = ?
            ORDER BY sort_order
        ");
        $stmt->execute([$productId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 📄 Получение документов товара
     */
    private function getProductDocuments(int $productId): array {
        $stmt = $this->pdo->prepare("
            SELECT type, COUNT(*) as count
            FROM product_documents
            WHERE product_id = ?
            GROUP BY type
        ");
        $stmt->execute([$productId]);
        
        $docs = [
            'certificates' => 0,
            'manuals' => 0,
            'drawings' => 0
        ];
        
        while ($row = $stmt->fetch()) {
            $type = $row['type'] . 's';
            if (isset($docs[$type])) {
                $docs[$type] = (int)$row['count'];
            }
        }
        
        return $docs;
    }

    /**
     * 📈 Получение популярности товара
     */
    private function getPopularityScore(int $productId): float {
        $stmt = $this->pdo->prepare("
            SELECT popularity_score
            FROM product_metrics
            WHERE product_id = ?
        ");
        $stmt->execute([$productId]);
        
        $score = $stmt->fetchColumn();
        return $score !== false ? (float)$score : 0.0;
    }

    /**
     * 📦 Получение общего остатка
     */
    private function getTotalStock(int $productId): int {
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(quantity - reserved), 0) 
            FROM stock_balances 
            WHERE product_id = ? AND quantity > reserved
        ");
        $stmt->execute([$productId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * 🏙️ Получение городов с наличием
     */
    private function getCitiesWithStock(int $productId): array {
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT c.city_id
            FROM stock_balances sb
            JOIN city_warehouse_mapping cwm ON sb.warehouse_id = cwm.warehouse_id
            JOIN cities c ON cwm.city_id = c.city_id
            WHERE sb.product_id = ? AND sb.quantity > sb.reserved
        ");
        $stmt->execute([$productId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * ❓ Проверка наличия товара
     */
    private function hasAnyStock(int $productId): bool {
        return $this->getTotalStock($productId) > 0;
    }

    /**
     * 🔤 Построение текста для поиска
     */
    private function buildSearchText(array $product): string {
        $parts = [
            $product['name'],
            $product['external_id'],
            $product['sku'],
            $product['brand_name'],
            $product['series_name'],
            $product['description']
        ];
        
        // Добавляем категории
        if (!empty($product['categories']['names'])) {
            $parts = array_merge($parts, $product['categories']['names']);
        }
        
        // Добавляем значения атрибутов
        if (!empty($product['attributes'])) {
            foreach ($product['attributes'] as $attr) {
                $parts[] = $attr['value'];
            }
        }
        
        // Объединяем и нормализуем
        $text = implode(' ', array_filter($parts));
        return $this->normalizeText($text);
    }

    /**
     * 💡 Построение данных для автодополнения с контекстами
     */
    private function buildSuggestData(array $product): array {
        $suggestions = [];

        // Подготавливаем контексты
        $contexts = [
            'category' => [],
            'brand' => []
        ];
        
        // Добавляем категории в контекст
        if (!empty($product['categories']['names'])) {
            $contexts['category'] = array_slice($product['categories']['names'], 0, 5); // Ограничиваем количество
        }
        
        // Добавляем бренд в контекст
        if (!empty($product['brand_name'])) {
            $contexts['brand'] = [$product['brand_name']];
        }

        // Название товара
        if (!empty($product['name'])) {
            $suggestions[] = [
                'input' => [$product['name']],
                'weight' => 100,
                'contexts' => $contexts
            ];
        }

        // Артикул
        if (!empty($product['external_id'])) {
            $suggestions[] = [
                'input' => [$product['external_id']],
                'weight' => 95,
                'contexts' => $contexts
            ];
        }

        // SKU
        if (!empty($product['sku'])) {
            $suggestions[] = [
                'input' => [$product['sku']],
                'weight' => 90,
                'contexts' => $contexts
            ];
        }

        // Бренд
        if (!empty($product['brand_name'])) {
            $suggestions[] = [
                'input' => [$product['brand_name']],
                'weight' => 70,
                'contexts' => $contexts
            ];
        }
        
        // Серия
        if (!empty($product['series_name'])) {
            $suggestions[] = [
                'input' => [$product['series_name']],
                'weight' => 60,
                'contexts' => $contexts
            ];
        }

        return $suggestions;
    }

    /**
     * 🔄 Переключение алиаса
     * 🔧 ПРАВКА 4: Исправлена работа с алиасами
     */
    private function switchAlias(): void {
        echo "🔄 === ПЕРЕКЛЮЧЕНИЕ АЛИАСА ===\n\n";
        
        try {
            $actions = [];
            
            // Получаем ВСЕ индексы с любыми алиасами
            try {
                $allAliases = $this->client->cat()->aliases(['format' => 'json']);
                $currentAliases = [];
                
                // Ищем все индексы с алиасом products_current
                foreach ($allAliases as $aliasInfo) {
                    if ($aliasInfo['alias'] === 'products_current') {
                        $currentAliases[] = $aliasInfo['index'];
                    }
                }
                
                if (!empty($currentAliases)) {
                    echo "📋 Найдены существующие алиасы:\n";
                    foreach ($currentAliases as $oldIndex) {
                        echo "   - $oldIndex\n";
                        $actions[] = ['remove' => ['index' => $oldIndex, 'alias' => 'products_current']];
                    }
                }
            } catch (\Exception $e) {
                echo "ℹ️ Алиас products_current не существует (первый запуск)\n";
            }
            
            // Проверяем, что новый индекс существует и содержит документы
            $stats = $this->client->indices()->stats(['index' => $this->newIndexName]);
            $docCount = $stats['indices'][$this->newIndexName]['primaries']['docs']['count'] ?? 0;
            
            if ($docCount === 0) {
                throw new \Exception("Новый индекс пустой! Отмена переключения алиаса.");
            }
            
            echo "📊 Документов в новом индексе: " . number_format($docCount) . "\n";
            
            // Добавляем новый алиас
            $actions[] = ['add' => ['index' => $this->newIndexName, 'alias' => 'products_current']];
            
            // Выполняем все действия одной транзакцией
            if (!empty($actions)) {
                $response = $this->client->indices()->updateAliases([
                    'body' => ['actions' => $actions]
                ]);
                
                if ($response['acknowledged'] === true) {
                    echo "✅ Алиас products_current успешно обновлен на " . $this->newIndexName . "\n";
                } else {
                    throw new \Exception("Не удалось обновить алиас");
                }
            }
            
        } catch (\Exception $e) {
            echo "❌ ОШИБКА при работе с алиасом: " . $e->getMessage() . "\n";
            Logger::error('Alias switch failed', [
                'error' => $e->getMessage(),
                'new_index' => $this->newIndexName
            ]);
            throw $e;
        }
        
        echo "\n";
    }

    /**
     * 🧹 Очистка старых индексов
     * 🔧 ПРАВКА 8: Исправлена логика очистки
     */
    private function cleanupOldIndices(): void {
        echo "🧹 === ОЧИСТКА ===\n\n";
        
        try {
            // Получаем все индексы products_*
            $catResponse = $this->client->cat()->indices([
                'index' => 'products_*',
                'format' => 'json'
            ]);
            
            $indices = [];
            foreach ($catResponse as $index) {
                $indices[] = $index['index'];
            }
            
            // Исключаем текущий индекс и индексы с алиасами
            $indicesToKeep = [$this->newIndexName];
            
            // Получаем индексы с алиасом products_current
            try {
                $aliasResponse = $this->client->indices()->getAlias(['name' => 'products_current']);
                $indicesToKeep = array_merge($indicesToKeep, array_keys($aliasResponse));
            } catch (\Exception $e) {
                // Алиас может не существовать
            }
            
            // Сортируем индексы по дате создания (из имени)
            sort($indices);
            
            // Оставляем только MAX_OLD_INDICES старых индексов
            $indicesToDelete = [];
            $oldIndices = array_diff($indices, $indicesToKeep);
            
            if (count($oldIndices) > MAX_OLD_INDICES) {
                $indicesToDelete = array_slice($oldIndices, 0, count($oldIndices) - MAX_OLD_INDICES);
            }
            
            foreach ($indicesToDelete as $index) {
                try {
                    $this->client->indices()->delete(['index' => $index]);
                    echo "🗑️ Удален старый индекс: $index\n";
                } catch (\Exception $e) {
                    echo "⚠️ Не удалось удалить $index: " . $e->getMessage() . "\n";
                }
            }
            
            if (empty($indicesToDelete)) {
                echo "ℹ️ Старые индексы не требуют очистки\n";
            }
            
        } catch (\Exception $e) {
            echo "⚠️ Ошибка при очистке: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
    }

    /**
     * 🎉 Финальный отчет
     */
    private function showFinalReport(): void {
        $duration = microtime(true) - $this->startTime;
        $speed = $this->processed > 0 ? $this->processed / $duration : 0;
        
        echo "🎉 === ГОТОВО! ===\n\n";
        echo "✅ Обработано товаров: " . number_format($this->processed) . "\n";
        echo "❌ Ошибок: " . number_format($this->errors) . "\n";
        echo "⏱️ Время: " . $this->formatTime($duration) . "\n";
        echo "🚀 Скорость: " . round($speed) . " товаров/сек\n";
        echo "💾 Пиковая память: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";
        echo "\n";
        echo "🔗 Индекс доступен по алиасу: products_current\n";
        echo "✅ Система готова к работе!\n\n";
    }

    // === Вспомогательные методы ===

    private function normalizeText(?string $text): string {
        if (empty($text)) return '';
        
        // Удаляем лишние пробелы и спецсимволы
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        return $text;
    }

    private function formatDate(?string $date): string {
        if (empty($date)) return date('c');
        
        $timestamp = strtotime($date);
        return $timestamp ? date('c', $timestamp) : date('c');
    }

    private function formatTime(float $seconds): string {
        if ($seconds < 60) {
            return round($seconds, 1) . 's';
        } elseif ($seconds < 3600) {
            return floor($seconds / 60) . 'm ' . round($seconds % 60) . 's';
        } else {
            return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
        }
    }

    private function updateProgress(int $current): void {
        $percent = round(($current / $this->totalProducts) * 100, 1);
        $bar = str_repeat('█', (int)($percent / 2)) . str_repeat('░', 50 - (int)($percent / 2));
        echo "\r[$bar] $percent% ({$this->processed}/{$this->totalProducts})";
    }

    private function initProgressBar(): void {
        echo "Progress: ";
    }

    private function handleBulkErrors(array $items): void {
        foreach ($items as $item) {
            if (isset($item['index']['error'])) {
                $this->errors++;
                error_log("Index error for ID {$item['index']['_id']}: " . json_encode($item['index']['error']));
            }
        }
    }

    private function handleError(Throwable $e): void {
        echo "\n\n💥 КРИТИЧЕСКАЯ ОШИБКА\n";
        echo "❌ " . $e->getMessage() . "\n";
        echo "📍 " . $e->getFile() . ":" . $e->getLine() . "\n\n";
        
        // Пытаемся удалить частично созданный индекс
        if (!empty($this->newIndexName)) {
            try {
                $this->client->indices()->delete(['index' => $this->newIndexName]);
                echo "🧹 Частично созданный индекс удален\n";
            } catch (Exception $cleanupError) {
                // Игнорируем
            }
        }
        
        exit(1);
    }

    private function getHeader(): string {
        return "
================================================================================
🚀 ИНДЕКСАТОР СТАТИЧЕСКИХ ДАННЫХ OPENSEARCH v5.1 - ИСПРАВЛЕННАЯ ВЕРСИЯ
================================================================================
📅 " . date('Y-m-d H:i:s') . "
🖥️ " . gethostname() . "
🐘 PHP " . PHP_VERSION . "
💾 Memory limit: " . ini_get('memory_limit') . "
================================================================================

";
    }

    /**
     * 📄 ИДЕАЛЬНАЯ конфигурация индекса для электротехники
     */
    private function getIndexConfiguration(): array {
        return [
            "settings" => [
                "number_of_shards" => 2,
                "number_of_replicas" => 1,
                "index.max_result_window" => 50000,
                "index.refresh_interval" => "30s",
                "index.mapping.total_fields.limit" => 2000,
                
                "analysis" => [
                    "normalizer" => [
                        "lowercase" => [
                            "type" => "custom", 
                            "filter" => ["lowercase"]
                        ],
                        "code_normalizer" => [
                            "type" => "custom",
                            "filter" => ["lowercase", "trim"]
                        ]
                    ],
                    
                    "char_filter" => [
                        "code_cleaner" => [
                            "type" => "pattern_replace",
                            "pattern" => "[\\s\\-\\._/,()\\[\\]]+",
                            "replacement" => ""
                        ],
                        "dimension_normalizer" => [
                            "type" => "pattern_replace",
                            "pattern" => "([0-9]+)[\\s]*([а-яё]+)",
                            "replacement" => "$1$2"
                        ],
                        "tech_symbols" => [
                            "type" => "mapping",
                            "mappings" => [
                                "Ø => диаметр",
                                "⌀ => диаметр", 
                                "° => градус",
                                "± => плюсминус",
                                "≤ => меньшеравно",
                                "≥ => большеравно",
                                "× => на"
                            ]
                        ]
                    ],
                    
                    "tokenizer" => [
                        "edge_ngram_tokenizer" => [
                            "type" => "edge_ngram",
                            "min_gram" => 2,
                            "max_gram" => 20,
                            "token_chars" => ["letter", "digit"]
                        ],
                        "code_tokenizer" => [
                            "type" => "pattern",
                            "pattern" => "[\\s\\-\\._/,()\\[\\]]+",
                            "group" => -1
                        ]
                    ],
                    
                    "filter" => [
                        "russian_stop" => [
                            "type" => "stop",
                            "stopwords" => [
                                "и", "в", "во", "не", "что", "он", "на", "я", "с", "со", "как", "а", "то", "все", "она", "так", "его", "но", "да", "ты", "к", "у", "же", "вы", "за", "бы", "по", "только", "ее", "мне", "было", "вот", "от", "меня", "еще", "нет", "о", "из", "ему", "теперь", "когда", "даже", "ну", "вдруг", "ли", "если", "уже", "или", "ни", "быть", "был", "него", "до", "вас", "нибудь", "опять", "уж", "вам", "ведь", "там", "потом", "себя", "ничего", "ей", "может", "они", "тут", "где", "есть", "надо", "ней", "для", "мы", "тебя", "их", "чем", "была", "сам", "чтоб", "без", "будто", "чего", "раз", "тоже", "себе", "под", "будет", "ж", "тогда", "кто", "этот", "того", "потому", "этого", "какой", "совсем", "ним", "здесь", "этом", "один", "почти", "мой", "тем", "чтобы", "нее", "сейчас", "были", "куда", "зачем", "всех", "никогда", "можно", "при", "наконец", "два", "об", "другой", "хоть", "после", "над", "больше", "тот", "через", "эти", "нас", "про", "всего", "них", "какая", "много", "разве", "три", "эту", "моя", "впрочем", "хорошо", "свою", "этой", "перед", "иногда", "лучше", "чуть", "том", "нельзя", "такой", "им", "более", "всегда", "конечно", "всю", "между"
                            ]
                        ],
                        "russian_stemmer" => [
                            "type" => "stemmer",
                            "language" => "russian"
                        ],
                        "synonym_filter" => [
                            "type" => "synonym_graph",
                            "synonyms" => [
                                "выключатель,переключатель,switch,коммутатор",
                                "автомат,автоматический выключатель,mcb,ва,автоматич",
                                "розетка,разъем,socket,гнездо",
                                "кабель,провод,cable,wire,жила,кабельный",
                                "щит,щиток,шкаф,panel,бокс,корпус",
                                "лампа,светильник,lamp,light,освещение",
                                "датчик,sensor,сенсор,детектор",
                                "реле,relay,контактор",
                                "трансформатор,transformer,тр,транс",
                                "предохранитель,fuse,плавкий,защита",
                                "изолятор,isolator,изоляция",
                                "заземление,ground,земля,pe",
                                "нуль,ноль,neutral,n",
                                "фаза,phase,l,линия",
                                "напряжение,voltage,вольт,v,в",
                                "ток,current,ампер,a,а",
                                "мощность,power,ватт,w,вт,квт",
                                "частота,frequency,hz,гц",
                                "сопротивление,resistance,ом,ohm",
                                "конденсатор,capacitor,емкость",
                                "резистор,resistor,сопротивление",
                                "диод,diode,led,светодиод",
                                "транзистор,transistor",
                                "микросхема,chip,ic,схема",
                                "плата,board,pcb,печатная",
                                "корпус,case,housing,оболочка",
                                "клемма,terminal,зажим,контакт",
                                "муфта,coupling,соединение",
                                "гильза,sleeve,наконечник",
                                "хомут,clamp,стяжка",
                                "труба,pipe,гофра,рукав",
                                "короб,box,лоток,канал",
                                "стойка,rack,шкаф,стеллаж",
                                "блок,block,модуль,unit",
                                "панель,panel,пластина",
                                "крепеж,mounting,крепление,фиксатор",
                                "ip20,ip44,ip54,ip65,ip67,степень защиты",
                                "din,дин,стандарт",
                                "гост,ост,ту,технические условия"
                            ]
                        ],
                        "edge_ngram_filter" => [
                            "type" => "edge_ngram",
                            "min_gram" => 2,
                            "max_gram" => 20
                        ],
                        "tech_units_filter" => [
                            "type" => "pattern_replace",
                            "pattern" => "([0-9]+)[\\s]*(мм|см|м|кг|г|в|вольт|а|ампер|вт|ватт|квт|гц|герц|ом|мкф|пф|нф)",
                            "replacement" => "$1$2"
                        ]
                    ],
                    
                    "analyzer" => [
                        "text_analyzer" => [
                            "tokenizer" => "standard",
                            "char_filter" => ["tech_symbols", "dimension_normalizer"],
                            "filter" => [
                                "lowercase", 
                                "russian_stop", 
                                "synonym_filter",
                                "russian_stemmer",
                                "tech_units_filter"
                            ]
                        ],
                        
                        "code_analyzer" => [
                            "tokenizer" => "code_tokenizer",
                            "char_filter" => ["code_cleaner"],
                            "filter" => ["lowercase"]
                        ],
                        
                        "autocomplete_analyzer" => [
                            "tokenizer" => "edge_ngram_tokenizer", 
                            "char_filter" => ["tech_symbols"],
                            "filter" => ["lowercase"]
                        ],
                        
                        "exact_analyzer" => [
                            "tokenizer" => "keyword",
                            "filter" => ["lowercase", "trim"]
                        ],
                        
                        "search_analyzer" => [
                            "tokenizer" => "standard",
                            "char_filter" => ["tech_symbols", "dimension_normalizer"],
                            "filter" => [
                                "lowercase",
                                "russian_stop",
                                "synonym_filter",
                                "tech_units_filter"
                            ]
                        ]
                    ]
                ]
            ],
            
            "mappings" => [
                "properties" => [
                    "product_id" => ["type" => "long"],
                    
                    "name" => [
                        "type" => "text",
                        "analyzer" => "text_analyzer",
                        "search_analyzer" => "search_analyzer",
                        "fields" => [
                            "keyword" => ["type" => "keyword"],
                            "exact" => ["type" => "text", "analyzer" => "exact_analyzer"],
                            "autocomplete" => [
                                "type" => "text",
                                "analyzer" => "autocomplete_analyzer",
                                "search_analyzer" => "search_analyzer"
                            ],
                            "raw" => ["type" => "keyword", "normalizer" => "lowercase"]
                        ]
                    ],
                    
                    "external_id" => [
                        "type" => "text",
                        "analyzer" => "code_analyzer",
                        "fields" => [
                            "keyword" => ["type" => "keyword", "normalizer" => "code_normalizer"],
                            "exact" => ["type" => "keyword"],
                            "prefix" => [
                                "type" => "text",
                                "analyzer" => "autocomplete_analyzer", 
                                "search_analyzer" => "exact_analyzer"
                            ],
                            "raw" => ["type" => "text", "analyzer" => "exact_analyzer"]
                        ]
                    ],
                    
                    "sku" => [
                        "type" => "text",
                        "analyzer" => "code_analyzer",
                        "fields" => [
                            "keyword" => ["type" => "keyword", "normalizer" => "code_normalizer"],
                            "exact" => ["type" => "keyword"],
                            "prefix" => [
                                "type" => "text",
                                "analyzer" => "autocomplete_analyzer",
                                "search_analyzer" => "exact_analyzer"
                            ]
                        ]
                    ],
                    
                    "description" => [
                        "type" => "text",
                        "analyzer" => "text_analyzer",
                        "search_analyzer" => "search_analyzer"
                    ],
                    
                    "brand_name" => [
                        "type" => "text",
                        "analyzer" => "text_analyzer",
                        "search_analyzer" => "search_analyzer",
                        "fields" => [
                            "keyword" => ["type" => "keyword"],
                            "exact" => ["type" => "keyword", "normalizer" => "lowercase"],
                            "autocomplete" => [
                                "type" => "text",
                                "analyzer" => "autocomplete_analyzer",
                                "search_analyzer" => "search_analyzer"
                            ]
                        ]
                    ],
                    
                    "series_name" => [
                        "type" => "text", 
                        "analyzer" => "text_analyzer",
                        "search_analyzer" => "search_analyzer",
                        "fields" => [
                            "keyword" => ["type" => "keyword"],
                            "exact" => ["type" => "keyword", "normalizer" => "lowercase"]
                        ]
                    ],
                    
                    "search_text" => [
                        "type" => "text",
                        "analyzer" => "text_analyzer",
                        "search_analyzer" => "search_analyzer"
                    ],
                    
                    "categories" => [
                        "type" => "text", 
                        "analyzer" => "text_analyzer",
                        "search_analyzer" => "search_analyzer"
                    ],
                    "category_ids" => ["type" => "integer"],
                    "unit" => ["type" => "keyword", "normalizer" => "lowercase"],
                    "min_sale" => ["type" => "integer"],
                    "weight" => ["type" => "float"],
                    "brand_id" => ["type" => "integer"],
                    "series_id" => ["type" => "integer"],
                    "images" => ["type" => "keyword"],
                    "popularity_score" => ["type" => "float"],
                    "total_stock" => ["type" => "integer"],
                    "cities_available" => ["type" => "integer"],
                    "has_stock" => ["type" => "boolean"],
                    "has_images" => ["type" => "boolean"],
                    "has_description" => ["type" => "boolean"],
                    "created_at" => ["type" => "date"],
                    "updated_at" => ["type" => "date"],
                    
                    "documents" => [
                        "type" => "object",
                        "properties" => [
                            "certificates" => ["type" => "integer"],
                            "manuals" => ["type" => "integer"],
                            "drawings" => ["type" => "integer"]
                        ]
                    ],
                    
                    "attributes" => [
                        "type" => "nested",
                        "properties" => [
                            "name" => ["type" => "keyword", "normalizer" => "lowercase"],
                            "value" => [
                                "type" => "text",
                                "analyzer" => "text_analyzer",
                                "search_analyzer" => "search_analyzer",
                                "fields" => [
                                    "keyword" => ["type" => "keyword", "normalizer" => "lowercase"],
                                    "exact" => ["type" => "keyword"],
                                    "numeric" => [
                                        "type" => "text",
                                        "analyzer" => "exact_analyzer"
                                    ]
                                ]
                            ],
                            "unit" => ["type" => "keyword", "normalizer" => "lowercase"]
                        ]
                    ],
                    
                    "numeric_props" => [
                        "type" => "nested",
                        "properties" => [
                            "name" => ["type" => "keyword", "normalizer" => "lowercase"],
                            "value" => ["type" => "float"],
                            "unit" => ["type" => "keyword", "normalizer" => "lowercase"],
                            "min_value" => ["type" => "float"],
                            "max_value" => ["type" => "float"]
                        ]
                    ],
                    
                    "suggest" => [
                        "type" => "completion",
                        "analyzer" => "autocomplete_analyzer",
                        "search_analyzer" => "search_analyzer",
                        "max_input_length" => 100,
                        "preserve_separators" => false,
                        "preserve_position_increments" => false,
                        "contexts" => [
                            [
                                "name" => "category",
                                "type" => "category"
                            ],
                            [
                                "name" => "brand", 
                                "type" => "category"
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }
}

// 🔧 ПРАВКА 9: Исправленный запуск для CLI режима
// 🚀 ЗАПУСК
if (php_sapi_name() === 'cli') {
    try {
        echo "🔧 Проверка зависимостей...\n";
        
        // Проверяем подключение к БД
        $testDb = Database::getConnection();
        if (!$testDb) {
            die("❌ Не удалось подключиться к БД\n");
        }
        
        echo "✅ Все зависимости в порядке\n\n";
        
        $indexer = new StaticProductIndexer();
        $indexer->run();
        
        echo "\n✅ Индексация завершена успешно!\n";
        echo "📌 Проверьте работу поиска через /api/search\n\n";
        
        exit(0);
    } catch (\Exception $e) {
        echo "\n💥 ФАТАЛЬНАЯ ОШИБКА: " . $e->getMessage() . "\n";
        echo "📍 " . $e->getFile() . ":" . $e->getLine() . "\n";
        
        if (isset($indexer) && !empty($indexer->newIndexName)) {
            echo "\n🧹 Пытаемся удалить незавершенный индекс...\n";
            try {
                $client = ClientBuilder::create()
                    ->setHosts(['localhost:9200'])
                    ->build();
                $client->indices()->delete(['index' => $indexer->newIndexName]);
                echo "✅ Незавершенный индекс удален\n";
            } catch (\Exception $cleanupError) {
                echo "⚠️ Не удалось удалить индекс\n";
            }
        }
        
        exit(1);
    }
} else {
    die("❌ Этот скрипт можно запускать только из командной строки\n");
}