<?php
// Configuração do banco de dados MySQL
class Database {
    private static $instance = null;
    private $connection;
    
    // Configurações do MySQL - ALTERE ESTAS CONFIGURAÇÕES
    private $host = 'localhost';
    private $dbname = 'teste';
    private $username = 'teste';
    private $password = '#D_p2ahElBb7zn6u';
    private $charset = 'utf8mb4';
    
    private function __construct() {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset={$this->charset}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $this->connection = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            throw new Exception("Erro na conexão com o banco de dados: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    // Método para criar as tabelas necessárias
    public function createTables() {
        $sql = "
        CREATE TABLE IF NOT EXISTS usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100) UNIQUE,
            role ENUM('admin', 'user') DEFAULT 'user',
            status ENUM('active', 'inactive') DEFAULT 'active',
            expires_at DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL
        );
        
        CREATE TABLE IF NOT EXISTS user_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            session_token VARCHAR(255) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
        );
        
        CREATE TABLE IF NOT EXISTS user_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            image_key VARCHAR(50) NOT NULL,
            image_path VARCHAR(500) NOT NULL,
            upload_type ENUM('file', 'url', 'default') DEFAULT 'file',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_image (user_id, image_key),
            FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            INDEX idx_user_image_key (user_id, image_key)
        );
        
        CREATE TABLE IF NOT EXISTS user_telegram_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            bot_token VARCHAR(255) NOT NULL,
            chat_id VARCHAR(50) NOT NULL,
            football_message TEXT,
            movie_series_message TEXT,
            scheduled_time VARCHAR(5),
            scheduled_football_theme INT DEFAULT 1,
            scheduled_delivery_enabled TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_telegram (user_id),
            FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            INDEX idx_user_telegram (user_id),
            INDEX idx_scheduled_delivery (scheduled_delivery_enabled, scheduled_time)
        );
        ";
        
        $this->connection->exec($sql);
        
        // Inserir imagens padrão para usuários existentes
        $this->insertDefaultImages();
    }
    
    // Método para inserir imagens padrão para usuários existentes
    private function insertDefaultImages() {
        $defaultImages = [
            'logo_banner_1' => 'imgelementos/semlogo.png',
            'logo_banner_2' => 'imgelementos/semlogo.png',
            'logo_banner_3' => 'imgelementos/semlogo.png',
            'logo_banner_4' => 'imgelementos/semlogo.png',
            'logo_movie_banner' => 'imgelementos/semlogo.png', // Nova entrada para logos de filmes/séries
            'background_banner_1' => 'wtec/Img/background_banner_1.png',
            'background_banner_2' => 'wtec/Img/background_banner_2.jpg',
            'background_banner_3' => 'wtec/Img/background_banner_3.png',
            'background_banner_4' => 'wtec/Img/background_banner_3.png', // Usando o mesmo fundo do tema 3 como padrão
            'card_banner_1' => 'wtec/card/card_banner_1.png',
            'card_banner_2' => 'wtec/card/card_banner_2.png',
            'card_banner_3' => 'wtec/card/card_banner_3.png',
            'card_banner_4' => 'imgelementos/fundo_jogo.png' // Usando o fundo_jogo como padrão
        ];
        
        // Buscar usuários que não têm imagens configuradas
        $stmt = $this->connection->prepare("
            SELECT DISTINCT u.id 
            FROM usuarios u 
            LEFT JOIN user_images ui ON u.id = ui.user_id 
            WHERE ui.user_id IS NULL
        ");
        $stmt->execute();
        $usersWithoutImages = $stmt->fetchAll();
        
        foreach ($usersWithoutImages as $user) {
            foreach ($defaultImages as $imageKey => $imagePath) {
                $stmt = $this->connection->prepare("
                    INSERT IGNORE INTO user_images (user_id, image_key, image_path, upload_type) 
                    VALUES (?, ?, ?, 'default')
                ");
                $stmt->execute([$user['id'], $imageKey, $imagePath]);
            }
        }
        
        // Adicionar logo_movie_banner para usuários existentes que não têm essa configuração
        $stmt = $this->connection->prepare("
            SELECT u.id 
            FROM usuarios u 
            LEFT JOIN user_images ui ON u.id = ui.user_id AND ui.image_key = 'logo_movie_banner'
            WHERE ui.user_id IS NULL
        ");
        $stmt->execute();
        $usersWithoutMovieLogo = $stmt->fetchAll();
        
        foreach ($usersWithoutMovieLogo as $user) {
            $stmt = $this->connection->prepare("
                INSERT IGNORE INTO user_images (user_id, image_key, image_path, upload_type) 
                VALUES (?, 'logo_movie_banner', 'imgelementos/semlogo.png', 'default')
            ");
            $stmt->execute([$user['id']]);
        }
        
        // Adicionar novas imagens do tema 4 para usuários existentes
        $newImages = [
            'logo_banner_4' => 'imgelementos/semlogo.png',
            'background_banner_4' => 'wtec/Img/background_banner_3.png',
            'card_banner_4' => 'imgelementos/fundo_jogo.png'
        ];
        
        foreach ($newImages as $imageKey => $imagePath) {
            $stmt = $this->connection->prepare("
                SELECT u.id 
                FROM usuarios u 
                LEFT JOIN user_images ui ON u.id = ui.user_id AND ui.image_key = ?
                WHERE ui.user_id IS NULL
            ");
            $stmt->execute([$imageKey]);
            $usersWithoutImage = $stmt->fetchAll();
            
            foreach ($usersWithoutImage as $user) {
                $stmt = $this->connection->prepare("
                    INSERT IGNORE INTO user_images (user_id, image_key, image_path, upload_type) 
                    VALUES (?, ?, ?, 'default')
                ");
                $stmt->execute([$user['id'], $imageKey, $imagePath]);
            }
        }
    }
}
?>