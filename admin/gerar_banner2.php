<?php
session_start();
if (!isset($_SESSION["usuario"])) {
    header("Location: login.php");
    exit();
}

// Handle different actions
$action = $_GET['action'] ?? 'generate';

// If action is view or download, serve the temporary file
if (in_array($action, ['view', 'download'])) {
    if (isset($_SESSION['current_banner_temp_path']) && file_exists($_SESSION['current_banner_temp_path'])) {
        $tempPath = $_SESSION['current_banner_temp_path'];
        $originalName = $_SESSION['current_banner_original_name'] ?? 'banner.png';
        
        header('Content-Type: image/png');
        header('Content-Length: ' . filesize($tempPath));
        
        if ($action === 'download') {
            header('Content-Disposition: attachment; filename="' . $originalName . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            // Serve file and delete after download
            if (readfile($tempPath)) {
                unlink($tempPath);
                unset($_SESSION['current_banner_temp_path']);
                unset($_SESSION['current_banner_original_name']);
            }
        } else {
            // Just serve the file for viewing
            readfile($tempPath);
        }
        exit;
    } else {
        // File not found, redirect to search
        header("Location: painel.php");
        exit;
    }
}

// Include necessary classes
require_once 'classes/UserImage.php';
require_once 'classes/BannerStats.php';

$apiKey = 'ec8237f367023fbadd38ab6a1596b40c';
$language = 'pt-BR';

if (!isset($_GET['name'])) {
    $pageTitle = "Erro - Banner";
    include "includes/header.php";
    ?>
    <div class="page-header">
        <h1 class="page-title">Erro na Geração do Banner</h1>
        <p class="page-subtitle">Nome do filme ou série não especificado</p>
    </div>
    
    <div class="card">
        <div class="card-body text-center py-12">
            <div class="mb-4">
                <i class="fas fa-exclamation-triangle text-6xl text-danger-500"></i>
            </div>
            <h3 class="text-xl font-semibold mb-2">Parâmetros Inválidos</h3>
            <p class="text-muted mb-6">Nome do filme ou série não foi especificado.</p>
            <a href="painel.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i>
                Voltar para Busca
            </a>
        </div>
    </div>
    <?php
    include "includes/footer.php";
    exit;
}

try {
    $name = urlencode($_GET['name']);
    $type = isset($_GET['type']) && $_GET['type'] == 'serie' ? 'tv' : 'movie';
    $tipoTexto = $type === 'tv' ? 'SÉRIE' : 'FILME';
    $year = isset($_GET['year']) ? $_GET['year'] : '';
    
    $searchUrl = "https://api.themoviedb.org/3/search/$type?api_key=$apiKey&language=$language&query=$name" . ($year ? "&year=$year" : '');
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'Mozilla/5.0 (compatible; FutBanner/1.0)'
        ]
    ]);
    
    $searchResponse = @file_get_contents($searchUrl, false, $context);
    if ($searchResponse === false) {
        throw new Exception("Erro ao conectar com a API do TMDB");
    }
    
    $searchData = json_decode($searchResponse, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Erro ao decodificar resposta da API");
    }

    if (empty($searchData['results'])) {
        throw new Exception("Nenhum filme ou série encontrado com o nome especificado");
    }

    $id = $searchData['results'][0]['id'];
    $mediaUrl = "https://api.themoviedb.org/3/$type/$id?api_key=$apiKey&language=$language";
    $elencoUrl = "https://api.themoviedb.org/3/$type/$id/credits?api_key=$apiKey&language=$language";
    
    $mediaResponse = @file_get_contents($mediaUrl, false, $context);
    $elencoResponse = @file_get_contents($elencoUrl, false, $context);
    
    if ($mediaResponse === false || $elencoResponse === false) {
        throw new Exception("Erro ao buscar detalhes do conteúdo");
    }

    $mediaData = json_decode($mediaResponse, true);
    $elencoData = json_decode($elencoResponse, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Erro ao processar dados do conteúdo");
    }

    $nome = isset($mediaData['title']) ? $mediaData['title'] : $mediaData['name'];
    $data = date('d/m/Y', strtotime($mediaData['release_date'] ?? $mediaData['first_air_date']));
    $categoria = implode(" • ", array_slice(array_column($mediaData['genres'], 'name'), 0, 3));
    $sinopse = $mediaData['overview'];
    $poster = "https://image.tmdb.org/t/p/w500" . $mediaData['poster_path'];
    $backdropUrl = "https://image.tmdb.org/t/p/w1280" . $mediaData['backdrop_path'];
    $atores = array_slice($elencoData['cast'], 0, 5);
    
    $maxSinopseLength = 300;
    if (strlen($sinopse) > $maxSinopseLength) {
        $sinopse = substr($sinopse, 0, $maxSinopseLength) . '...';
    }
    
    $imageWidth = 1280;
    $imageHeight = 853;
    $image = imagecreatetruecolor($imageWidth, $imageHeight);
    
    $backgroundImage = @imagecreatefromjpeg($backdropUrl);
    if ($backgroundImage === false) {
        throw new Exception("Erro ao carregar imagem de fundo");
    }
    
    imagecopyresampled($image, $backgroundImage, 0, 0, 0, 0, $imageWidth, $imageHeight, imagesx($backgroundImage), imagesy($backgroundImage));
    
    $intensidadeBase = 70;
    $numPassos = 5;  
    $deslocamento = 1; 
    for ($i = 0; $i < $numPassos; $i++) {
        $alfa = min(127, $intensidadeBase + ($i * ( (127 - $intensidadeBase) / ($numPassos - 1) )) );
        $corSombraDesfocada = imagecolorallocatealpha($image, 0, 0, 0, $alfa);
        imagefilledrectangle(
            $image,
            -$i * $deslocamento,
            -$i * $deslocamento,
            $imageWidth + $i * $deslocamento,
            $imageHeight + $i * $deslocamento,
            $corSombraDesfocada
        );
    }
    $sombraCentralAlfa = min(127, $intensidadeBase + 10);
    $corSombraCentral = imagecolorallocatealpha($image, 0, 0, 0, $sombraCentralAlfa);
    imagefilledrectangle($image, 0, 0, $imageWidth, $imageHeight, $corSombraCentral);
    
    $whiteColor = imagecolorallocate($image, 255, 255, 255);
    $yellowColor = imagecolorallocate($image, 255, 215, 0);
    $fontPath = __DIR__ . '/fonts/dejavu-sans-bold.ttf';
    $fontSize = 20;
    
    function wrapText($text, $font, $fontSize, $maxWidth) {
        $wrappedText = '';
        $words = explode(' ', $text);
        $line = '';
        foreach ($words as $word) {
            $testLine = $line . ' ' . $word;
            $testBox = imagettfbbox($fontSize, 0, $font, $testLine);
            $testWidth = $testBox[2] - $testBox[0];
            if ($testWidth <= $maxWidth) {
                $line = $testLine;
            } else {
                $wrappedText .= trim($line) . "\n";
                $line = $word;
            }
        }
        $wrappedText .= trim($line);
        return $wrappedText;
    }

    // Carregar logo do usuário para banners de filmes/séries
    $userImage = new UserImage();
    $userId = $_SESSION['user_id'];
    $logoContent = $userImage->getImageContent($userId, 'logo_movie_banner');

    if ($logoContent !== false) {
        $icon = @imagecreatefromstring($logoContent);
        if ($icon !== false) {
            $logoLarguraDesejada = 150;
            $logoPosX = 6; $logoPosY = 10;
            $logoWidthOriginal = imagesx($icon);
            $logoHeightOriginal = imagesy($icon);
            $logoHeight = (int)($logoHeightOriginal * ($logoLarguraDesejada / $logoWidthOriginal));
            $logoRedimensionada = imagecreatetruecolor($logoLarguraDesejada, $logoHeight);
            imagealphablending($logoRedimensionada, false); imagesavealpha($logoRedimensionada, true);
            imagecopyresampled($logoRedimensionada, $icon, 0, 0, 0, 0, $logoLarguraDesejada, $logoHeight, $logoWidthOriginal, $logoHeightOriginal);
            imagecopy($image, $logoRedimensionada, $logoPosX, $logoPosY, 0, 0, $logoLarguraDesejada, $logoHeight);
            imagedestroy($icon); imagedestroy($logoRedimensionada);
        }
    }

    $urlDispositivos = 'https://i.ibb.co/qLZQSbBp/Design-sem-nome-9.png';
    $imgDispositivosResource = @imagecreatefrompng($urlDispositivos);
    if ($imgDispositivosResource !== false) {
        $imgDisX = 445;
        $imgDisY = 685;
        $imgDisWidth = 416;
        $imgDisHeight = 96;
        imagecopyresampled(
            $image,
            $imgDispositivosResource, 
            $imgDisX,
            $imgDisY,
            0,
            0,
            $imgDisWidth,
            $imgDisHeight,
            imagesx($imgDispositivosResource), 
            imagesy($imgDispositivosResource)
        );
        imagedestroy($imgDispositivosResource);
    }
    
    $tipoFontSize = 24;
    $fundoVermelho = imagecolorallocate($image, 255, 0, 0); 
    $margemVerticalTipo = 10;
    $margemHorizontalTipo = 20;
    $tipoBox = imagettfbbox($tipoFontSize, 0, $fontPath, $tipoTexto);
    $tipoLargura = abs($tipoBox [2] - $tipoBox [0]);
    $tipoAltura = abs($tipoBox [7] - $tipoBox [1]);
    $boxXTipo = 445;
    $boxYTipo = 295;
    $boxLarguraTipo = $tipoLargura + ($margemHorizontalTipo * 2);
    $boxAlturaTipo = $tipoAltura + ($margemVerticalTipo * 2);
    imagefilledrectangle($image, $boxXTipo, $boxYTipo, $boxXTipo + $boxLarguraTipo, $boxYTipo + $boxAlturaTipo, $fundoVermelho);
    $textoXTipo = $boxXTipo + $margemHorizontalTipo;
    $textoYTipo = $boxYTipo + $margemVerticalTipo + $tipoAltura;
    imagettftext($image, $tipoFontSize, 0, $textoXTipo, $textoYTipo, $whiteColor, $fontPath, $tipoTexto);
    
    $textoFixo = ("JÁ DISPONÍVEL");
    $fontSizeFixo = 38;
    $posicaoX = 455;
    $posicaoY = 165;
    $textBoxFixo = imagettfbbox($fontSizeFixo, 0, $fontPath, $textoFixo);
    $textoLargura = $textBoxFixo[2] - $textBoxFixo[0];
    $fundoAmarelo = imagecolorallocate($image, 255, 215, 0);
    $corPreta = imagecolorallocate($image, 0, 0, 0);
    $margemVertical = -6;
    $margemHorizontal = 10;
    imagefilledrectangle(
        $image,
        $posicaoX - $margemHorizontal,
        $posicaoY + $textBoxFixo[1] - $margemVertical,
        $posicaoX + $textoLargura + $margemHorizontal,
        $posicaoY + $textBoxFixo[7] + $margemVertical,
        $fundoAmarelo
    );
    imagettftext($image, $fontSizeFixo, 0, $posicaoX, $posicaoY, $corPreta, $fontPath, $textoFixo);
    
    $tamanhoMaximoFonte = 33;
    $tamanhoMinimoFonte = 7;
    $larguraMaximaTitulo = $imageWidth - 460;
    $posicaoX_titulo = 445;
    $posterY = 80;
    $posicaoY_titulo = $posterY + 165;
    $tamanhoFonteFinal = $tamanhoMaximoFonte;
    while ($tamanhoFonteFinal > $tamanhoMinimoFonte) {
        $textBox = imagettfbbox($tamanhoFonteFinal, 0, $fontPath, $nome);
        $larguraTexto = abs($textBox[2] - $textBox[0]);
        if ($larguraTexto <= $larguraMaximaTitulo) {
            break;
        }
        $tamanhoFonteFinal--;
    }
    imagettftext(
        $image,
        $tamanhoFonteFinal,
        0,
        $posicaoX_titulo,
        $posicaoY_titulo,
        $whiteColor,
        $fontPath,
        $nome
    );
    
    $posterImage = @imagecreatefromjpeg($poster);
    if ($posterImage !== false) {
        $posterWidth = 400;
        $posterHeight = 650;
        $posterX = 10;
        imagecopyresampled($image, $posterImage, $posterX, $posterY, 0, 0, $posterWidth, $posterHeight, imagesx($posterImage), imagesy($posterImage));
        imagedestroy($posterImage);
    }
    
    $tamanhoMaximoFonteCat = 25;
    $tamanhoMinimoFonteCat = 10;
    $larguraMaximaCategoria = $imageWidth - 600;
    $posicaoX_cat = 600;
    $posicaoY_cat = 330;
    $tamanhoFonteFinalCat = $tamanhoMaximoFonteCat;
    while ($tamanhoFonteFinalCat > $tamanhoMinimoFonteCat) {
        $textBoxCat = imagettfbbox($tamanhoFonteFinalCat, 0, $fontPath, $categoria);
        $larguraTextoCat = abs($textBoxCat[2] - $textBoxCat[0]);
        if ($larguraTextoCat <= $larguraMaximaCategoria) {
            break;
        }
        $tamanhoFonteFinalCat--;
    }
    imagettftext(
        $image,
        $tamanhoFonteFinalCat,
        0,
        $posicaoX_cat,
        $posicaoY_cat,
        $whiteColor,
        $fontPath,
        $categoria
    );
    
    $nota = $mediaData['vote_average'];
    $notaFormatada = number_format($nota, 1, ',', '');
    $estrelaCheia = '★';
    $estrelaVazia = '☆';
    $totalEstrelas = 10;
    $numEstrelasCheias = round($nota);
    $numEstrelasVazias = $totalEstrelas - $numEstrelasCheias;
    $textoEstrelas = '';
    for ($i = 0; $i < $numEstrelasCheias; $i++) {
        $textoEstrelas .= $estrelaCheia;
    }
    for ($i = 0; $i < $numEstrelasVazias; $i++) {
        $textoEstrelas .= $estrelaVazia;
    }
    $posicaoX_estrelas = 450;
    $posicaoY_estrelas = 285;
    $fontSizeEstrelas = 28;
    $fontSizeNota = 24;
    imagettftext(
        $image,
        $fontSizeEstrelas,
        0,
        $posicaoX_estrelas,
        $posicaoY_estrelas,
        $yellowColor,
        $fontPath,
        $textoEstrelas
    );
    $boxEstrelas = imagettfbbox($fontSizeEstrelas, 0, $fontPath, $textoEstrelas);
    $larguraEstrelas = $boxEstrelas[2] - $boxEstrelas[0];
    $posicaoX_nota = $posicaoX_estrelas + $larguraEstrelas + 15; 
    imagettftext(
        $image,
        $fontSizeNota,
        0,
        $posicaoX_nota,
        $posicaoY_estrelas,
        $whiteColor,
        $fontPath,
        $notaFormatada
    );
    
    $maxWidth = $imageWidth - 460;
    $wrappedSinopse = wrapText($sinopse, $fontPath, $fontSize, $maxWidth);
    imagettftext($image, $fontSize, 0, 445, 390, $whiteColor, $fontPath, $wrappedSinopse); 
    
    imagettftext($image, 20, 0, 215, $imageHeight - 30, $whiteColor, $fontPath, "O MELHOR DO STREAMING VOCÊ SÓ ENCONTRA AQUI");
    imagettftext($image, 16, 0, 445, $imageHeight - 180, $whiteColor, $fontPath, "DISPONÍVEL EM DIVERSOS APARELHOS");
    
    // Registrar estatística do banner gerado
    $bannerStats = new BannerStats();
    $bannerStats->recordBannerGeneration($_SESSION['user_id'], 'movie', 'tema2', $nome);
    
    // Gerar nome de arquivo temporário único
    $tempFileName = 'banner_tema2_' . uniqid() . '_' . time() . '.png';
    $tempFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tempFileName;
    $originalFileName = 'banner_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $nome) . '_tema2.png';
    
    // Salvar imagem no arquivo temporário
    if (!imagepng($image, $tempFilePath)) {
        throw new Exception("Erro ao salvar a imagem temporária");
    }
    
    // Limpar memória
    imagedestroy($image);
    imagedestroy($backgroundImage);
    
    // Armazenar informações na sessão
    $_SESSION['current_banner_temp_path'] = $tempFilePath;
    $_SESSION['current_banner_original_name'] = $originalFileName;
    
    // Exibir página com layout completo
    $pageTitle = "Banner Gerado - Tema 2";
    include "includes/header.php";
    ?>
    
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-image text-primary-500 mr-3"></i>
            Banner Gerado com Sucesso
        </h1>
        <p class="page-subtitle">Tema 2 - <?php echo htmlspecialchars($nome); ?></p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Banner Preview -->
        <div class="lg:col-span-2">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Prévia do Banner</h3>
                    <p class="card-subtitle">Seu banner personalizado está pronto</p>
                </div>
                <div class="card-body">
                    <div class="banner-preview-container">
                        <img src="gerar_banner2.php?action=view" alt="Banner Gerado" class="banner-preview-image">
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions Panel -->
        <div class="space-y-6">
            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Ações Disponíveis</h3>
                </div>
                <div class="card-body">
                    <div class="space-y-3">
                        <a href="gerar_banner2.php?action=download&name=<?php echo urlencode($_GET['name']); ?>&type=<?php echo urlencode($_GET['type'] ?? 'filme'); ?>&year=<?php echo urlencode($_GET['year'] ?? ''); ?>" class="btn btn-primary w-full">
                            <i class="fas fa-download"></i>
                            Baixar Banner
                        </a>
                        
                        <a href="send_telegram.php?banner_path=<?php echo urlencode($tempFilePath); ?>&banner_name=<?php echo urlencode($originalFileName); ?>&content_name=<?php echo urlencode($nome); ?>&type=<?php echo urlencode($type === 'tv' ? 'serie' : 'filme'); ?>" class="btn btn-success w-full">
                            <i class="fab fa-telegram"></i>
                            Enviar para Telegram
                        </a>
                        
                        <a href="painel.php" class="btn btn-secondary w-full">
                            <i class="fas fa-search"></i>
                            Nova Busca
                        </a>
                    </div>
                </div>
            </div>

            <!-- Banner Info -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Informações do Banner</h3>
                </div>
                <div class="card-body">
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <span class="text-muted">Título:</span>
                            <span class="font-medium"><?php echo htmlspecialchars($nome); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted">Tipo:</span>
                            <span><?php echo $tipoTexto; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted">Tema:</span>
                            <span>Tema 2 (Moderno)</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted">Resolução:</span>
                            <span>1280x853px</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted">Formato:</span>
                            <span>PNG</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Try Other Themes -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Outros Temas</h3>
                </div>
                <div class="card-body">
                    <div class="space-y-2">
                        <a href="gerar_banner.php?name=<?php echo urlencode($_GET['name']); ?>&type=<?php echo urlencode($_GET['type'] ?? 'filme'); ?>&year=<?php echo urlencode($_GET['year'] ?? ''); ?>" class="btn btn-outline w-full text-sm">
                            <i class="fas fa-palette"></i>
                            Tema 1 (Clássico)
                        </a>
                        <a href="gerar_banner3.php?name=<?php echo urlencode($_GET['name']); ?>&type=<?php echo urlencode($_GET['type'] ?? 'filme'); ?>&year=<?php echo urlencode($_GET['year'] ?? ''); ?>" class="btn btn-outline w-full text-sm">
                            <i class="fas fa-magic"></i>
                            Tema 3 (Premium)
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .banner-preview-container {
            width: 100%;
            background: var(--bg-secondary);
            border-radius: var(--border-radius);
            padding: 1rem;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 400px;
        }

        .banner-preview-image {
            max-width: 100%;
            max-height: 600px;
            height: auto;
            border-radius: var(--border-radius-sm);
            box-shadow: var(--shadow-lg);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        .btn-outline:hover {
            background: var(--bg-tertiary);
        }

        .space-y-2 > * + * {
            margin-top: 0.5rem;
        }

        .space-y-3 > * + * {
            margin-top: 0.75rem;
        }

        .space-y-6 > * + * {
            margin-top: 1.5rem;
        }

        .w-full {
            width: 100%;
        }

        .text-sm {
            font-size: 0.875rem;
        }

        .mr-3 {
            margin-right: 0.75rem;
        }

        @media (max-width: 768px) {
            .banner-preview-container {
                min-height: 300px;
                padding: 0.5rem;
            }
        }
    </style>

    <?php
    include "includes/footer.php";

} catch (Exception $e) {
    // Em caso de erro, exibir página de erro com layout
    $pageTitle = "Erro - Banner";
    include "includes/header.php";
    ?>
    
    <div class="page-header">
        <h1 class="page-title">Erro na Geração do Banner</h1>
        <p class="page-subtitle">Ocorreu um problema ao processar sua solicitação</p>
    </div>

    <div class="card">
        <div class="card-body text-center py-12">
            <div class="mb-4">
                <i class="fas fa-exclamation-triangle text-6xl text-danger-500"></i>
            </div>
            <h3 class="text-xl font-semibold mb-2">Erro no Sistema</h3>
            <p class="text-muted mb-6"><?php echo htmlspecialchars($e->getMessage()); ?></p>
            <div class="flex gap-4 justify-center">
                <a href="painel.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i>
                    Voltar para Busca
                </a>
                <button onclick="location.reload()" class="btn btn-secondary">
                    <i class="fas fa-redo"></i>
                    Tentar Novamente
                </button>
            </div>
        </div>
    </div>
    
    <?php
    include "includes/footer.php";
}
?>