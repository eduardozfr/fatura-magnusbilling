# 📄 Configuração do Projeto Fatura MBilling

Este guia descreve o processo de configuração do projeto **MBilling** no servidor.

## 📁 Estrutura de Diretórios

1. Crie uma pasta no diretório `/var/www/html/mbilling/`:

   ```bash
   sudo mkdir /var/www/html/mbilling/fatura
   ```

2. Defina as permissões de propriedade e grupo para `asterisk`:

   ```bash
   sudo chown -R asterisk:asterisk /var/www/html/mbilling/fatura
   sudo chmod -R 755 /var/www/html/mbilling/fatura
   ```

---

## 📑 Configuração do FPDF

3. Garanta que a pasta `fpdf` esteja dentro da pasta criada, copiando-a do diretório principal do projeto:

   ```bash
   cp -r /var/www/html/mbilling/fpdf /var/www/html/mbilling/fatura/
   ```

---

## 🛠️ Configuração dos Arquivos

4. **Editar as credenciais do banco de dados:**

   - Abra os arquivos e edite as respectivas linhas:

     ```bash
     nano /var/www/html/mbilling/fatura/index.php
     ```

     - Linha **65**: Insira a senha do banco de dados.

     ```bash
     nano /var/www/html/mbilling/fatura/login.php
     ```

     - Linha **18**: Insira a senha do banco de dados.

   - Caso não saiba a senha, consulte o arquivo:

     ```bash
     cat /etc/asterisk/res_config_mysql.conf
     ```

---

## 🔑 Permissões de Login

5. O acesso deve ser restrito apenas a usuários dos seguintes grupos:

   - **Administrator (ID: 1)**  
   - **Gerenciamento (ID: 5)**

   Para isso, edite a linha **45** no arquivo `login.php`:

   ```bash
   nano /var/www/html/mbilling/fatura/login.php
   ```

   **Exemplo de código para verificar os grupos:**

   ```php
   if ($user_group_id != 1 && $user_group_id != 5) {
       die("Acesso negado.");
   }
   ```

---

## 💡 Sugestões e Melhorias

Sinta-se à vontade para sugerir melhorias ou abrir uma issue no repositório do GitHub! 🚀

