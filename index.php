#Conexão com o Banco de Dados (conexao.php)
1. Script SQL do Banco de Dados (banco.sql)

CREATE DATABASE IF NOT EXISTS sistema_bancario DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sistema_bancario;

CREATE TABLE IF NOT EXISTS contas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titular VARCHAR(100) NOT NULL,
    saldo DECIMAL(10, 2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB;

-- Inserindo os dados iniciais solicitados
INSERT INTO contas (id, titular, saldo) VALUES
(1, 'Ana', 1000.00),
(2, 'Bruno', 500.00),
(3, 'Carla', 200.00);






# Etapa 1 — Estrutura HTML5 e Estilização CSS

<?php
$host = 'localhost';
$dbname = 'sistema_bancario';
$username = 'root'; // Ajuste conforme seu ambiente
$password = '';     // Ajuste conforme seu ambiente

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    // Configura o PDO para lançar exceções em caso de erros
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["erro" => "Erro na conexão com o banco de dados: " . $e->getMessage()]);
    exit;
}
?>





--Etapa 1 — Estrutura HTML5 e Estilização CSS (index.html)--
<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'conexao.php';

// Recebe os dados enviados via JSON do Fetch API
$inputData = json_decode(file_get_contents('php://input'), true);

$contaOrigem = isset($inputData['conta_origem']) ? trim($inputData['conta_origem']) : '';
$contaDestino = isset($inputData['conta_destino']) ? trim($inputData['conta_destino']) : '';
$valor = isset($inputData['valor']) ? floatval($inputData['valor']) : 0;

// Validações básicas (Etapa 3)
if (empty($contaOrigem) || empty($contaDestino)) {
    http_response_code(400);
    echo json_encode(["erro" => "Contas de origem e destino devem ser informadas."]);
    exit;
}

if ($contaOrigem === $contaDestino) {
    http_response_code(400);
    echo json_encode(["erro" => "A conta de origem não pode ser igual à conta de destino."]);
    exit;
}

if ($valor <= 0) {
    http_response_code(400);
    echo json_encode(["erro" => "O valor da transferência deve ser maior que zero."]);
    exit;
}

try {
    // Etapa 5: Início do controle transacional (Atomicidade)
    $pdo->beginTransaction();

    // 1. Verificar existência da conta de origem e bloquear linha para concorrência segura (FOR UPDATE)
    $stmtOrigem = $pdo->prepare("SELECT saldo FROM contas WHERE id = :id FOR UPDATE");
    $stmtOrigem->bindValue(':id', $contaOrigem, PDO::PARAM_INT);
    $stmtOrigem->execute();
    $origemDados = $stmtOrigem->fetch(PDO::FETCH_ASSOC);

    if (!$origemDados) {
        throw new Exception("Conta de origem não encontrada.");
    }

    $saldoOrigem = floatval($origemDados['saldo']);

    // 2. Verificar se a conta de origem possui saldo suficiente
    if ($saldoOrigem < $valor) {
        throw new Exception("Transferência não realizada: saldo insuficiente.");
    }

    // 3. Verificar existência da conta de destino
    $stmtDestino = $pdo->prepare("SELECT id FROM contas WHERE id = :id FOR UPDATE");
    $stmtDestino->bindValue(':id', $contaDestino, PDO::PARAM_INT);
    $stmtDestino->execute();

    if (!$stmtDestino->fetch()) {
        throw new Exception("Conta de destino não encontrada.");
    }

    // 4. Executar o débito na conta de origem
    $stmtDebito = $pdo->prepare("UPDATE contas SET saldo = saldo - :valor WHERE id = :id");
    $stmtDebito->bindValue(':valor', $valor, PDO::PARAM);
    $stmtDebito->bindValue(':id', $contaOrigem, PDO::PARAM_INT);
    $stmtDebito->execute();

    if ($stmtDebito->rowCount() === 0) {
        throw new Exception("Falha ao debitar a conta de origem.");
    }

    // 5. Executar o crédito na conta de destino
    $stmtCredito = $pdo->prepare("UPDATE contas SET saldo = saldo + :valor WHERE id = :id");
    $stmtCredito->bindValue(':valor', $valor, PDO::PARAM);
    $stmtCredito->bindValue(':id', $contaDestino, PDO::PARAM_INT);
    $stmtCredito->execute();

    if ($stmtCredito->rowCount() === 0) {
        throw new Exception("Falha ao creditar a conta de destino.");
    }

    // 6. Confirma a transação se todas as etapas ocorreram com sucesso
    $pdo->commit();

    http_response_code(200);
    echo json_encode(["sucesso" => "Transferência de R$ " . number_format($valor, 2, ',', '.') . " realizada com sucesso."]);

} catch (Exception $e) {
    // Etapa 5: Reverte todas as alterações caso ocorra qualquer erro ou exceção
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    echo json_encode(["erro" => $e->getMessage()]);
}
?>