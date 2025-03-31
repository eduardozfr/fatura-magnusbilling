<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['loggedin'])) {
    header("Location: login.php");
    exit;
}

// Verifica se a extensão mbstring está habilitada
if (!function_exists('mb_convert_encoding')) {
    die("A extensão mbstring não está habilitada no PHP. Por favor, ative-a no php.ini");
}

// Inclui a biblioteca FPDF
require_once __DIR__ . '/fpdf/fpdf.php';

class PDF extends FPDF {
    function RoundedRect($x, $y, $w, $h, $r, $style = '') {
        $k = $this->k;
        $hp = $this->h;
        if($style=='F') $op='f';
        elseif($style=='FD' || $style=='DF') $op='B';
        else $op='S';
        $MyArc = 4/3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m',($x+$r)*$k,($hp-$y)*$k ));
        $xc = $x+$w-$r;
        $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l', $xc*$k,($hp-$y)*$k ));
        $this->_Arc($xc + $r*$MyArc, $yc - $r, $xc + $r, $yc - $r*$MyArc, $xc + $r, $yc);
        $xc = $x+$w-$r;
        $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',($x+$w)*$k,($hp-$yc)*$k));
        $this->_Arc($xc + $r, $yc + $r*$MyArc, $xc + $r*$MyArc, $yc + $r, $xc, $yc + $r);
        $xc = $x+$r;
        $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',$xc*$k,($hp-($y+$h))*$k));
        $this->_Arc($xc - $r*$MyArc, $yc + $r, $xc - $r, $yc + $r*$MyArc, $xc - $r, $yc);
        $xc = $x+$r;
        $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l',($x)*$k,($hp-$yc)*$k ));
        $this->_Arc($xc - $r, $yc - $r*$MyArc, $xc - $r*$MyArc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }
    
    function _Arc($x1, $y1, $x2, $y2, $x3, $y3) {
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', 
            $x1*$this->k, ($h-$y1)*$this->k,
            $x2*$this->k, ($h-$y2)*$this->k, 
            $x3*$this->k, ($h-$y3)*$this->k));
    }
}

class DBProcessor {
    private $data = [];
    private $accounts = [];
    private $userIds = [];
    private $clientInfo = [];
    private $db;

    public function __construct() {
        $dbname = 'mbilling';
        $dbuser = 'mbillingUser';
        $dbpass = 'DIGITE SUA SENHA';
        $dbhost = 'localhost';

        try {
            $this->db = new PDO("mysql:host=$dbhost;dbname=$dbname;charset=utf8", $dbuser, $dbpass);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            throw new Exception("Erro ao conectar ao banco de dados: " . $e->getMessage());
        }
    }

    public function extractAccounts() {
        try {
            $stmt = $this->db->query("SELECT id, username, firstname, lastname FROM pkg_user WHERE active = 1");
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->accounts = [];
            $this->userIds = [];
            $this->clientInfo = [];
            
            foreach ($result as $row) {
                $username = trim($row['username']);
                $this->accounts[] = [
                    'username' => $username,
                    'firstname' => $row['firstname'],
                    'lastname' => $row['lastname']
                ];
                $this->userIds[$username] = $row['id'];
                $this->clientInfo[$username] = [
                    'firstname' => $row['firstname'],
                    'lastname' => $row['lastname']
                ];
            }
            
            if (empty($this->accounts)) {
                throw new Exception("Nenhuma conta ativa encontrada na tabela pkg_user.");
            }
            return $this->accounts;
        } catch (PDOException $e) {
            throw new Exception("Erro ao extrair contas da tabela pkg_user: " . $e->getMessage());
        }
    }

    public function getClientInfo($username) {
        return $this->clientInfo[$username] ?? null;
    }

    public function processDB($startDate, $endDate, $selectedAccounts) {
        $startDateTime = date('Y-m-d 00:00:00', strtotime($startDate));
        $endDateTime = date('Y-m-d 23:59:59', strtotime($endDate));

        try {
            if (empty($this->userIds)) {
                $this->extractAccounts();
            }

            $selectedUserIds = [];
            foreach ($selectedAccounts as $username) {
                $username = trim($username);
                if (isset($this->userIds[$username])) {
                    $selectedUserIds[] = $this->userIds[$username];
                }
            }

            if (empty($selectedUserIds)) {
                throw new Exception("Nenhum ID de usuário válido encontrado para os usernames selecionados: " . implode(', ', $selectedAccounts));
            }

            $placeholders = implode(',', array_fill(0, count($selectedUserIds), '?'));

            $query = "SELECT 
                        cdr.starttime, 
                        cdr.calledstation, 
                        cdr.callerid, 
                        cdr.id_prefix,
                        cdr.sessiontime, 
                        cdr.sessionbill,
                        user.username
                      FROM pkg_cdr cdr
                      INNER JOIN pkg_user user ON cdr.id_user = user.id
                      WHERE cdr.id_user IN ($placeholders) 
                      AND cdr.starttime BETWEEN ? AND ?
                      ORDER BY cdr.starttime ASC";
            
            $params = array_merge($selectedUserIds, [$startDateTime, $endDateTime]);
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            $this->data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $this->data;
        } catch (PDOException $e) {
            throw new Exception("Erro ao processar dados do banco: " . $e->getMessage());
        }
    }

    public function generatePDF($startDate, $endDate, $accounts, $outputMode = 'D', $filename = '') {
        $pdf = new PDF();
        $pdf->AddPage();
        
        $convertToLatin1 = function($text) {
            return mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
        };

        $logoPath = __DIR__ . '/logo.png';
        if (file_exists($logoPath)) {
            $imageInfo = getimagesize($logoPath);
            if ($imageInfo && ($imageInfo[2] === IMAGETYPE_PNG || $imageInfo[2] === IMAGETYPE_JPEG)) {
                $pdf->Image($logoPath, 10, 15, 40);
            }
        }

        $pdf->SetFont('Arial', 'B', 16);
        $pdf->SetY(15);
        $pdf->SetX(60);
        $pdf->Cell(0, 10, $convertToLatin1('FATURA DE TELEFONIA FIXA'), 0, 1, 'C');
        
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetX(60);
        
        if (count($accounts) == 1 && $clientInfo = $this->getClientInfo($accounts[0])) {
            $pdf->Cell(0, 5, $convertToLatin1('Cliente: ' . $clientInfo['firstname'] . ' ' . $clientInfo['lastname']), 0, 1);
            $pdf->SetX(60);
            $pdf->Cell(0, 5, $convertToLatin1('Conta: ' . $accounts[0]), 0, 1);
        } else {
            $pdf->Cell(0, 5, $convertToLatin1('Contas: ' . implode(', ', $accounts)), 0, 1);
        }
        
        $startDateBr = date('d/m/Y', strtotime($startDate));
        $endDateBr = date('d/m/Y', strtotime($endDate));
        $pdf->SetX(60);
        $pdf->Cell(0, 5, $convertToLatin1("Período: $startDateBr a $endDateBr"), 0, 1);
        
        $emissao = date('d/m/Y');
        $pdf->SetX(60);
        $pdf->Cell(0, 5, $convertToLatin1("Data de emissão: $emissao"), 0, 1);
        
        $pdf->Ln(15);
        $pdf->SetDrawColor(0, 102, 204);
        $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
        $pdf->Ln(10);

        $headerFillColor = array(0, 102, 204);
        $rowFillColor = array(240, 240, 240);
        $borderColor = array(200, 200, 200);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetFillColor($headerFillColor[0], $headerFillColor[1], $headerFillColor[2]);
        $pdf->SetTextColor(255, 255, 255);
        
        $colWidths = array(40, 30, 30, 30, 30, 30);
        
        $pdf->Cell($colWidths[0], 8, $convertToLatin1('Data - Hora'), 1, 0, 'C', true);
        $pdf->Cell($colWidths[1], 8, $convertToLatin1('Número'), 1, 0, 'C', true);
        $pdf->Cell($colWidths[2], 8, $convertToLatin1('Identificador'), 1, 0, 'C', true);
        $pdf->Cell($colWidths[3], 8, $convertToLatin1('Tipo'), 1, 0, 'C', true);
        $pdf->Cell($colWidths[4], 8, $convertToLatin1('Duração'), 1, 0, 'C', true);
        $pdf->Cell($colWidths[5], 8, $convertToLatin1('Valor (R$)'), 1, 0, 'C', true);
        $pdf->Ln();

        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(0, 0, 0);
        $totalTime = 0;
        $totalValue = 0;
        $incomingCalls = 0;
        $outgoingCalls = 0;
        $fill = false;

        foreach ($this->data as $row) {
            $fill = !$fill;
            $pdf->SetFillColor($rowFillColor[0], $rowFillColor[1], $rowFillColor[2]);
            $pdf->SetDrawColor($borderColor[0], $borderColor[1], $borderColor[2]);

            $dateTimeBr = date('d/m/Y H:i:s', strtotime($row['starttime']));
            $callType = (is_null($row['id_prefix']) || $row['id_prefix'] === '') ? 'Entrada' : 'Saída';
            $durationSeconds = (int)$row['sessiontime'];
            $hours = floor($durationSeconds / 3600);
            $minutes = floor(($durationSeconds % 3600) / 60);
            $seconds = $durationSeconds % 60;
            $duration = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
            $value = floatval($row['sessionbill'] ?? 0);

            $pdf->Cell($colWidths[0], 8, $convertToLatin1($dateTimeBr), 1, 0, 'L', $fill);
            $pdf->Cell($colWidths[1], 8, $convertToLatin1($row['calledstation'] ?? 'N/A'), 1, 0, 'L', $fill);
            $pdf->Cell($colWidths[2], 8, $convertToLatin1($row['callerid'] ?? 'N/A'), 1, 0, 'L', $fill);
            $pdf->Cell($colWidths[3], 8, $convertToLatin1($callType), 1, 0, 'C', $fill);
            $pdf->Cell($colWidths[4], 8, $duration, 1, 0, 'C', $fill);
            $pdf->Cell($colWidths[5], 8, number_format($value, 2, ',', '.'), 1, 0, 'R', $fill);
            $pdf->Ln();

            $totalTime += $durationSeconds;
            $totalValue += $value;
            if ($callType === 'Entrada') {
                $incomingCalls++;
            } else {
                $outgoingCalls++;
            }
        }

	// Verifica se há espaço suficiente (85 é a altura estimada + margem)
	if ($pdf->GetY() + 85 > $pdf->GetPageHeight() - 20) {
		$pdf->AddPage();
	}
	
	// Espaço antes do bloco final
	$pdf->Ln(20);
	
	// Retângulo principal com fundo neutro
	$pdf->SetFillColor(245, 245, 245); // Cinza muito claro, discreto
	$pdf->SetDrawColor(60, 60, 60); // Borda cinza escura, séria
	$pdf->SetLineWidth(0.2); // Borda fina e firme
	$pdf->Rect(10, $pdf->GetY(), 190, 80, 'DF'); // Altura ajustada
	
	// Cabeçalho com tom sério
	$pdf->SetFillColor(50, 50, 50); // Cinza escuro quase preto
	$pdf->Rect(10, $pdf->GetY(), 190, 12, 'F');
	$pdf->SetFont('Helvetica', 'B', 12); // Helvetica em negrito para autoridade
	$pdf->SetTextColor(255, 255, 255); // Texto branco para contraste
	$pdf->SetXY(10, $pdf->GetY() + 3);
	$pdf->Cell(190, 6, $convertToLatin1("RESUMO DA FATURA"), 0, 1, 'C');
	
	// Estrutura tabular com divisórias claras
	$yStart = $pdf->GetY() + 4; // Espaço após o cabeçalho
	$pdf->SetFont('Helvetica', 'B', 10);
	$pdf->SetTextColor(40, 40, 40); // Preto suave para títulos
	$pdf->SetFillColor(235, 235, 235); // Fundo alternado cinza claro
	$pdf->SetDrawColor(150, 150, 150); // Bordas internas cinza médio
	
	// Linha 1: Tempo Total
	$pdf->SetXY(15, $yStart + 5);
	$pdf->Cell(95, 10, $convertToLatin1("Tempo Total de Uso"), 1, 0, 'L', 1);
	$pdf->SetFont('Helvetica', '', 10);
	$pdf->SetTextColor(60, 60, 60); // Cinza escuro para valores
	$totalHours = floor($totalTime / 3600);
	$totalMinutes = floor(($totalTime % 3600) / 60);
	$totalSeconds = $totalTime % 60;
	$totalDuration = sprintf('%02dh %02dm %02ds', $totalHours, $totalMinutes, $totalSeconds);
	$pdf->Cell(80, 10, $totalDuration, 1, 1, 'R', 1);
	
	// Linha 2: Chamadas de Entrada
	$pdf->SetFont('Helvetica', 'B', 10);
	$pdf->SetTextColor(40, 40, 40);
	$pdf->SetXY(15, $pdf->GetY());
	$pdf->Cell(95, 10, $convertToLatin1("Chamadas de Entrada"), 1, 0, 'L');
	$pdf->SetFont('Helvetica', '', 10);
	$pdf->SetTextColor(60, 60, 60);
	$pdf->Cell(80, 10, $incomingCalls, 1, 1, 'R');
	
	// Linha 3: Chamadas de Saída
	$pdf->SetFont('Helvetica', 'B', 10);
	$pdf->SetTextColor(40, 40, 40);
	$pdf->SetXY(15, $pdf->GetY());
	$pdf->Cell(95, 10, $convertToLatin1("Chamadas de Saída"), 1, 0, 'L', 1);
	$pdf->SetFont('Helvetica', '', 10);
	$pdf->SetTextColor(60, 60, 60);
	$pdf->Cell(80, 10, $outgoingCalls, 1, 1, 'R', 1);
	
	// Linha 4: Total de Chamadas
	$pdf->SetFont('Helvetica', 'B', 10);
	$pdf->SetTextColor(40, 40, 40);
	$pdf->SetXY(15, $pdf->GetY());
	$pdf->Cell(95, 10, $convertToLatin1("Total de Chamadas"), 1, 0, 'L');
	$pdf->SetFont('Helvetica', '', 10);
	$pdf->SetTextColor(60, 60, 60);
	$pdf->Cell(80, 10, $incomingCalls + $outgoingCalls, 1, 1, 'R');
	
	// Linha divisória antes do Valor Total
	$pdf->SetDrawColor(60, 60, 60);
	$pdf->SetLineWidth(0.3);
	$pdf->Line(15, $pdf->GetY() + 5, 185, $pdf->GetY() + 5);
	
	// Linha 5: Valor Total (destaque sério)
	$pdf->SetFont('Helvetica', 'B', 11);
	$pdf->SetTextColor(40, 40, 40);
	$pdf->SetXY(15, $pdf->GetY() + 8);
	$pdf->SetFillColor(225, 225, 225); // Fundo cinza mais escuro para destaque
	$pdf->Cell(95, 12, $convertToLatin1("Valor Total a Pagar"), 1, 0, 'L', 1);
	$pdf->SetFont('Helvetica', 'B', 16);
	$pdf->SetTextColor(0, 0, 0); // Preto puro para máxima seriedade
	$pdf->Cell(80, 12, 'R$ ' . number_format($totalValue, 2, ',', '.'), 1, 1, 'R', 1);
	
	// Rodapé com linha simples e sólida
	$pdf->SetDrawColor(60, 60, 60);
	$pdf->SetLineWidth(0.4);
	$pdf->Line(10, $pdf->GetY() + 4, 200, $pdf->GetY() + 4);
	
	// Gera o PDF
	$pdf->Output($outputMode, $filename);
	exit;
    }
}

// Processa a requisição
$message = '';
$accounts = [];
$processor = new DBProcessor();

try {
    $accounts = $processor->extractAccounts();
} catch (Exception $e) {
    $message = "Erro ao carregar contas do banco: " . htmlspecialchars($e->getMessage());
}

// Rota para geração de PDF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_pdf'])) {
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $selectedAccounts = $_POST['account'] ?? [];
    $action = $_POST['action'] ?? 'download';

    if (empty($startDate) || empty($endDate) || empty($selectedAccounts)) {
        $message = "Erro: Preencha todos os campos.";
    } else {
        try {
            $processor->processDB($startDate, $endDate, $selectedAccounts);
            
            $pdfFileName = "fatura_" . implode("_", $selectedAccounts) . "_" . $startDate . "_" . $endDate . ".pdf";
            
            if ($action === 'view') {
                $viewToken = bin2hex(random_bytes(16));
                $_SESSION['pdf_view_' . $viewToken] = [
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'accounts' => $selectedAccounts
                ];
                
                echo json_encode(['status' => 'success', 'token' => $viewToken]);
                exit;
            } else {
                $processor->generatePDF($startDate, $endDate, $selectedAccounts, 'D', $pdfFileName);
                exit;
            }
        } catch (Exception $e) {
            $message = "Erro ao gerar a fatura: " . htmlspecialchars($e->getMessage());
        }
    }
}

// Rota para visualização do PDF
if (isset($_GET['view_pdf'])) {
    $token = $_GET['token'] ?? '';
    if (!empty($token) && isset($_SESSION['pdf_view_' . $token])) {
        $data = $_SESSION['pdf_view_' . $token];
        unset($_SESSION['pdf_view_' . $token]);
        
        try {
            $processor = new DBProcessor();
            $processor->processDB($data['startDate'], $data['endDate'], $data['accounts']);
            
            $pdfFileName = "fatura_" . implode("_", $data['accounts']) . "_" . $data['startDate'] . "_" . $data['endDate'] . ".pdf";
            $processor->generatePDF($data['startDate'], $data['endDate'], $data['accounts'], 'I', $pdfFileName);
            exit;
        } catch (Exception $e) {
            die("Erro ao gerar PDF: " . htmlspecialchars($e->getMessage()));
        }
    } else {
        die("Token de visualização inválido ou expirado.");
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gerador de Faturas - Telefonia Fixa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f0f2f5;
            font-family: 'Helvetica', 'Arial', sans-serif;
            padding: 0;
            margin: 0;
        }
        .container {
            max-width: 960px;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            padding: 40px;
            margin: 40px auto;
            border: 1px solid #d0d0d0;
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid #333333;
            padding-bottom: 15px;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #333333;
            font-size: 24px;
            font-weight: 700;
            margin: 0;
        }
        .header img {
            height: 40px;
            margin-right: 15px;
        }
        .logout .btn {
            background-color: #dc3545;
            border: none;
            font-weight: 500;
            padding: 6px 20px;
            font-size: 14px;
        }
        .form-section h2 {
            color: #333333;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 8px;
        }
        .form-label {
            color: #444444;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .form-control {
            border: 1px solid #b0b0b0;
            border-radius: 4px;
            font-size: 14px;
            padding: 8px 12px;
            color: #555555;
        }
        .account-search-container {
            position: relative;
        }
        .account-search {
            width: 100%;
            padding-right: 30px;
        }
        .search-icon {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #666666;
            pointer-events: none;
        }
        .account-list {
            max-height: 180px;
            overflow-y: auto;
            border: 1px solid #b0b0b0;
            border-radius: 4px;
            background-color: #fafafa;
            margin-top: 5px;
            padding: 0;
            list-style: none;
        }
        .account-list li {
            padding: 8px 12px;
            cursor: pointer;
            font-size: 14px;
            color: #555555;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .account-list li:last-child {
            border-bottom: none;
        }
        .account-list li:hover {
            background-color: #f0f0f0;
        }
        .account-list li.selected {
            background-color: #d0d0d0;
        }
        .selected-accounts {
            margin-top: 10px;
            padding: 10px;
            background-color: #f8f8f8;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            font-size: 14px;
            color: #444444;
            min-height: 40px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .selected-accounts ul {
            margin: 0;
            padding: 0;
            list-style: none;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .selected-accounts li {
            background-color: #d0d0d0;
            padding: 4px 10px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .form-text {
            font-size: 12px;
            color: #666666;
        }
        .action-buttons {
            margin-top: 30px;
            display: flex;
            gap: 15px;
        }
        .btn-custom {
            font-weight: 600;
            font-size: 14px;
            padding: 10px 25px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary {
            background-color: #404040;
            border: none;
        }
        .btn-primary:hover {
            background-color: #555555;
        }
        .btn-success {
            background-color: #2b2b2b;
            border: none;
        }
        .btn-success:hover {
            background-color: #404040;
        }
        .message {
            margin-top: 20px;
            padding: 12px 20px;
            border-radius: 4px;
            font-size: 14px;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert-success {
            background-color: #e8f5e9;
            border-left-color: #2e7d32;
            color: #2e7d32;
        }
        .alert-danger {
            background-color: #ffebee;
            border-left-color: #c62828;
            color: #c62828;
        }
        .alert-warning {
            background-color: #fff3e0;
            border-left-color: #f57c00;
            color: #f57c00;
        }
        .bi {
            font-size: 16px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; align-items: center;">
                <img src="logo.png" alt="Logo" onerror="this.style.display='none'">
                <h1>Gerador de Faturas - Telefonia Fixa</h1>
            </div>
            <div class="logout">
                <a href="login.php?logout=1" class="btn btn-danger">
                    <i class="bi bi-box-arrow-right"></i> Sair
                </a>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="message alert <?php echo strpos($message, 'Erro') === false ? 'alert-success' : 'alert-danger'; ?>">
                <i class="bi <?php echo strpos($message, 'Erro') === false ? 'bi-check-circle' : 'bi-exclamation-triangle'; ?>"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($accounts)): ?>
            <div class="form-section">
                <h2>Gerar Fatura</h2>
                <form id="pdfForm" method="POST" class="mb-4">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="account-search" class="form-label">
                                <i class="bi bi-person-lines-fill"></i> Contas
                            </label>
                            <div class="account-search-container">
                                <input type="text" class="form-control account-search" id="account-search" placeholder="Buscar contas...">
                                <i class="bi bi-search search-icon"></i>
                            </div>
                            <ul class="account-list" id="account-list">
                                <?php foreach ($accounts as $account): 
                                    $username = $account['username'];
                                    $displayText = $username;
                                    $clientInfo = $processor->getClientInfo($username);
                                    if ($clientInfo) {
                                        $displayText .= ' - ' . $clientInfo['firstname'] . ' ' . $clientInfo['lastname'];
                                    }
                                ?>
                                    <li data-value="<?php echo htmlspecialchars($username); ?>" data-display="<?php echo htmlspecialchars($displayText); ?>">
                                        <i class="bi bi-person"></i> <?php echo htmlspecialchars($displayText); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <div id="account-inputs"></div>
                            <div class="selected-accounts" id="selected-accounts">
                                <i class="bi bi-check-square"></i>
                                <span>Contas selecionadas:</span>
                                <ul id="selected-accounts-list"></ul>
                            </div>
                            <div class="form-text">Clique para selecionar contas (múltiplas permitidas)</div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="start_date" class="form-label">
                                <i class="bi bi-calendar-event"></i> Data Inicial
                            </label>
                            <input type="date" class="form-control" name="start_date" id="start_date" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="end_date" class="form-label">
                                <i class="bi bi-calendar-check"></i> Data Final
                            </label>
                            <input type="date" class="form-control" name="end_date" id="end_date" required>
                        </div>
                    </div>
                    <input type="hidden" name="generate_pdf" value="1">
                    
                    <div class="action-buttons">
                        <button type="submit" class="btn btn-success btn-custom" name="action" value="download">
                            <i class="bi bi-download"></i> Baixar PDF
                        </button>
                        <button type="button" id="viewBtn" class="btn btn-primary btn-custom" name="action" value="view">
                            <i class="bi bi-eye"></i> Visualizar
                        </button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-circle"></i> Nenhuma conta disponível para exibição.
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const searchInput = document.getElementById('account-search');
        const accountList = document.getElementById('account-list');
        const accounts = accountList.getElementsByTagName('li');
        const accountInputsContainer = document.getElementById('account-inputs');
        const selectedAccountsList = document.getElementById('selected-accounts-list');
        let selectedAccounts = [];

        searchInput.addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            for (let account of accounts) {
                const text = account.textContent.toLowerCase();
                account.style.display = text.includes(filter) ? '' : 'none';
            }
        });

        for (let account of accounts) {
            account.addEventListener('click', function() {
                const value = this.getAttribute('data-value');
                const displayText = this.getAttribute('data-display');
                const index = selectedAccounts.findIndex(item => item.value === value);
                if (index === -1) {
                    selectedAccounts.push({ value, displayText });
                    this.classList.add('selected');
                } else {
                    selectedAccounts.splice(index, 1);
                    this.classList.remove('selected');
                }
                updateSelectedAccounts();
            });
        }

        function updateSelectedAccounts() {
            // Atualizar inputs hidden
            accountInputsContainer.innerHTML = '';
            selectedAccounts.forEach(item => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'account[]';
                input.value = item.value;
                accountInputsContainer.appendChild(input);
            });
            const event = new Event('change');
            accountInputsContainer.dispatchEvent(event);

            // Atualizar lista de contas selecionadas
            selectedAccountsList.innerHTML = '';
            if (selectedAccounts.length === 0) {
                selectedAccountsList.innerHTML = '<li>Nenhuma conta selecionada</li>';
            } else {
                selectedAccounts.forEach(item => {
                    const li = document.createElement('li');
                    li.innerHTML = `<i class="bi bi-person-check"></i> ${item.displayText}`;
                    selectedAccountsList.appendChild(li);
                });
            }
        }

        document.getElementById('pdfForm').addEventListener('submit', function(e) {
            if (!selectedAccounts.length) {
                e.preventDefault();
                alert('Por favor, selecione pelo menos uma conta.');
            }
        });

        document.getElementById('viewBtn').addEventListener('click', function(e) {
            e.preventDefault();
            if (!selectedAccounts.length) {
                alert('Por favor, selecione pelo menos uma conta.');
                return;
            }
            const form = document.getElementById('pdfForm');
            const formData = new FormData(form);
            formData.set('action', 'view');
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    window.open(`?view_pdf=1&token=${data.token}`, '_blank');
                } else {
                    alert('Erro ao gerar PDF para visualização');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Erro ao processar requisição');
            });
        });

        document.getElementById('pdfForm').addEventListener('submit', function(e) {
            const actionBtn = e.submitter;
            if (actionBtn && actionBtn.name === 'action' && actionBtn.value === 'view') {
                e.preventDefault();
                document.getElementById('viewBtn').click();
            }
        });

        // Inicializar a lista de contas selecionadas
        updateSelectedAccounts();
    </script>
</body>
</html>