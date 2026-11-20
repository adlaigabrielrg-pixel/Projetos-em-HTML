<?php
// Configuração e Conexão com o Banco de Dados embutida
$host = 'localhost';
$dbname = 'sistema_bancario';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    $pdo->exec("USE `$dbname`;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS contas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titular VARCHAR(100) NOT NULL,
        saldo DECIMAL(10, 2) NOT NULL DEFAULT 0.00
    ) ENGINE=InnoDB;");

    $stmtCheck = $pdo->query("SELECT COUNT(*) FROM contas");
    if ($stmtCheck->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO contas (id, titular, saldo) VALUES
            (1, 'Ana Silva', 1000.00),
            (2, 'Bruno Costa', 500.00),
            (3, 'Carla Souza', 200.00);");
    }
} catch (PDOException $e) {
    http_response_code(500);
    die("Erro na conexão ou configuração do banco: " . $e->getMessage());
}

// -------------------------------------------------------------------------
// PROCESSAMENTO DE REQUISIÇÕES AJAX (GET para Consulta / POST para Transferência)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['acao'] ?? '') === 'consultar') {
    header('Content-Type: application/json; charset=utf-8');

    $idConta = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1]
    ]);

    if (!$idConta) {
        http_response_code(400);
        echo json_encode(["erro" => "Informe um ID de conta válido."]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT id, titular, saldo FROM contas WHERE id = :id");
        $stmt->bindValue(':id', $idConta, PDO::PARAM_INT);
        $stmt->execute();
        $conta = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$conta) {
            http_response_code(404);
            echo json_encode(["erro" => "Conta informada não foi encontrada."]);
            exit;
        }

        http_response_code(200);
        echo json_encode($conta);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["erro" => "Erro ao consultar o saldo."]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $inputData = json_decode(file_get_contents('php://input'), true);

    $contaOrigem = filter_var($inputData['conta_origem'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1]
    ]);
    $contaDestino = filter_var($inputData['conta_destino'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1]
    ]);
    $valor = isset($inputData['valor']) && is_numeric($inputData['valor'])
        ? round((float)$inputData['valor'], 2)
        : 0.0;

    if (!$contaOrigem || !$contaDestino) {
        http_response_code(400);
        echo json_encode(["erro" => "Contas de origem e destino válidas devem ser informadas."]);
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
        $pdo->beginTransaction();

        // Bloqueia em ordem crescente de ID para reduzir risco de deadlock
        $idsOrdenados = [$contaOrigem, $contaDestino];
        sort($idsOrdenados, SORT_NUMERIC);

        $stmtLock = $pdo->prepare("SELECT id, saldo FROM contas WHERE id = :id FOR UPDATE");
        $contasBloqueadas = [];

        foreach ($idsOrdenados as $idLock) {
            $stmtLock->bindValue(':id', $idLock, PDO::PARAM_INT);
            $stmtLock->execute();
            $row = $stmtLock->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $contasBloqueadas[(int)$row['id']] = $row;
            }
        }

        if (!isset($contasBloqueadas[$contaOrigem])) {
            throw new Exception("Conta de origem não encontrada.");
        }

        if (!isset($contasBloqueadas[$contaDestino])) {
            throw new Exception("Conta de destino não encontrada.");
        }

        if ((float)$contasBloqueadas[$contaOrigem]['saldo'] < $valor) {
            throw new Exception("Transferência não realizada: saldo insuficiente.");
        }

        $stmtDebito = $pdo->prepare("UPDATE contas SET saldo = saldo - :valor WHERE id = :id");
        $stmtDebito->bindValue(':valor', $valor);
        $stmtDebito->bindValue(':id', $contaOrigem, PDO::PARAM_INT);
        $stmtDebito->execute();

        $stmtCredito = $pdo->prepare("UPDATE contas SET saldo = saldo + :valor WHERE id = :id");
        $stmtCredito->bindValue(':valor', $valor);
        $stmtCredito->bindValue(':id', $contaDestino, PDO::PARAM_INT);
        $stmtCredito->execute();

        $pdo->commit();

        http_response_code(200);
        echo json_encode([
            "sucesso" => "Transferência de R$ " . number_format($valor, 2, ',', '.') . " realizada com sucesso."
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(400);
        echo json_encode(["erro" => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Transferências Financeiras</title>
    <style>
        :root {
            --primary-color: #2563eb;
            --success-color: #16a34a;
            --error-color: #dc2626;
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --text-color: #1e293b;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
        }
        .container { width: 100%; max-width: 600px; }
        header { text-align: center; margin-bottom: 25px; }
        header h1 { color: var(--primary-color); font-size: 1.8rem; margin-bottom: 5px; }
        .card {
            background-color: var(--card-bg);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            margin-bottom: 20px;
        }
        .card h2 { font-size: 1.2rem; margin-top: 0; margin-bottom: 15px; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: 600; margin-bottom: 5px; font-size: 0.9rem; }
        input { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; font-size: 1rem; }
        input:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); }
        button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 15px;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            width: 100%;
            transition: background-color 0.2s;
        }
        button:hover { background-color: #1d4ed8; }
        #mensagem { margin-top: 15px; padding: 12px; border-radius: 6px; font-weight: 500; display: none; }
        .sucesso { background-color: #dcfce7; color: var(--success-color); border: 1px solid #bbf7d0; }
        .erro { background-color: #fee2e2; color: var(--error-color); border: 1px solid #fecaca; }
        .resultado-saldo { margin-top: 10px; font-weight: bold; color: var(--primary-color); }
    </style>
</head>
<body>

    <div class="container">
        <header>
            <h1>Cooperativa Financeira</h1>
            <p>Portal de Gestão de Contas e Transferências</p>
        </header>

        <div class="card">
            <h2>Consultar Saldo</h2>
            <div class="form-group">
                <label for="contaSaldo">ID da Conta:</label>
                <input type="number" id="contaSaldo" min="1" placeholder="Ex: 1, 2 ou 3">
            </div>
            <button id="btnConsultar">Consultar Saldo</button>
            <div id="resultadoSaldo" class="resultado-saldo"></div>
        </div>

        <div class="card">
            <h2>Realizar Transferência</h2>
            <div class="form-group">
                <label for="contaOrigem">Conta de Origem (ID):</label>
                <input type="number" id="contaOrigem" min="1" placeholder="Ex: 1">
            </div>
            <div class="form-group">
                <label for="contaDestino">Conta de Destino (ID):</label>
                <input type="number" id="contaDestino" min="1" placeholder="Ex: 2">
            </div>
            <div class="form-group">
                <label for="valorTransferencia">Valor (R$):</label>
                <input type="number" step="0.01" min="0.01" id="valorTransferencia" placeholder="0.00">
            </div>
            <button id="btnTransferir">Efetuar Transferência</button>
        </div>

        <div id="mensagem"></div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const btnConsultar = document.getElementById('btnConsultar');
            const btnTransferir = document.getElementById('btnTransferir');
            const divMensagem = document.getElementById('mensagem');
            const formatadorBRL = new Intl.NumberFormat('pt-BR', {
                style: 'currency',
                currency: 'BRL'
            });

            function mostrarMensagem(texto, tipo) {
                divMensagem.style.display = 'block';
                divMensagem.textContent = texto;
                divMensagem.className = tipo;
            }

            btnConsultar.addEventListener('click', () => {
                const idConta = document.getElementById('contaSaldo').value.trim();
                const resultadoSaldo = document.getElementById('resultadoSaldo');
                resultadoSaldo.textContent = '';

                if (!idConta) {
                    mostrarMensagem('Informe o ID da conta para consulta.', 'erro');
                    return;
                }

                fetch(`?acao=consultar&id=${encodeURIComponent(idConta)}`)
                    .then(response => response.json().then(data => {
                        if (!response.ok) {
                            throw new Error(data.erro || 'Erro ao consultar saldo.');
                        }
                        return data;
                    }))
                    .then(data => {
                        resultadoSaldo.textContent = `Titular: ${data.titular} | Saldo Atual: ${formatadorBRL.format(parseFloat(data.saldo))}`;
                        divMensagem.style.display = 'none';
                    })
                    .catch(error => {
                        mostrarMensagem(error.message, 'erro');
                    });
            });

            btnTransferir.addEventListener('click', () => {
                const origem = document.getElementById('contaOrigem').value.trim();
                const destino = document.getElementById('contaDestino').value.trim();
                const valor = document.getElementById('valorTransferencia').value.trim();

                if (!origem || !destino || !valor) {
                    mostrarMensagem('Preencha todos os campos para realizar a transferência.', 'erro');
                    return;
                }

                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        conta_origem: origem,
                        conta_destino: destino,
                        valor: valor
                    })
                })
                .then(response => response.json().then(data => {
                    if (!response.ok) {
                        throw new Error(data.erro || 'Erro no processamento da transferência.');
                    }
                    return data;
                }))
                .then(data => {
                    mostrarMensagem(data.sucesso, 'sucesso');
                    document.getElementById('contaOrigem').value = '';
                    document.getElementById('contaDestino').value = '';
                    document.getElementById('valorTransferencia').value = '';
                })
                .catch(error => {
                    mostrarMensagem(error.message, 'erro');
                });
            });
        });
    </script>
</body>
</html>
