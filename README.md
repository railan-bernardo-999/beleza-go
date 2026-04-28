# E-commerce Local API

API REST em Laravel 13 para lojas locais que trabalham com **retirada na loja** ou **pagamento na entrega**. Sem integração com gateway de pagamento online.

## 🚀 Stack

- **PHP**: 8.4-FPM Alpine
- **Framework**: Laravel 13.6
- **Banco**: MySQL 8.0
- **Cache/Filas**: Redis
- **Servidor**: Nginx
- **Container**: Docker + Docker Compose
- **Auth**: Laravel Sanctum

## 📦 Funcionalidades

### Para a Loja
- Gestão de produtos, categorias e estoque
- Controle de pedidos com status: `pendente`, `confirmado`, `em_preparo`, `saiu_entrega`, `pronto_retirada`, `entregue`, `cancelado`
- Cadastro de clientes
- Relatórios de vendas
- Configuração de taxa de entrega por bairro/região
- Horários de funcionamento

### Para o Cliente
- Catálogo de produtos sem necessidade de login
- Carrinho de compras
- Checkout simplificado
- Opções: **Retirada na loja** ou **Entrega**
- Formas de pagamento: Dinheiro, PIX, Cartão na entrega
- Acompanhamento de pedido

### O que NÃO tem
- ❌ Pagamento online via cartão/gateway
- ❌ Split de pagamento
- ❌ Assinaturas recorrentes

## 🐳 Instalação com Docker

### Pré-requisitos
- Docker 24+
- Docker Compose 2.20+

### 1. Clone o projeto
```bash
git clone https://github.com/railan-bernardo-999/beleza-go.git
cd beleza-go
```

### 2. Configure o ambiente
```bash
cp .env.example .env
```

Ajuste o `.env`:
```env
APP_NAME="Loja Local API"
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=laravel
DB_PASSWORD=secret

CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=redis
REDIS_PORT=6379
```

### 3. Suba os containers
```bash
docker-compose up -d --build
```

### 4. Instale dependências e configure o Laravel
```bash
docker-compose exec app composer install
docker-compose exec app php artisan key:generate
docker-compose exec app php artisan install:api
docker-compose exec app php artisan migrate --seed
docker-compose exec app php artisan storage:link
```

### 5. Acessos
- **API**: http://localhost:8000
- **PHPMyAdmin**: http://localhost:8080 - user: `root`, pass: `secret`

## 🔐 Autenticação

A API usa Laravel Sanctum com tokens Bearer.

### Endpoints Auth
| Método | Endpoint | Descrição | Auth |
| --- | --- | --- | --- |
| POST | `/api/v1/register` | Criar conta | Não |
| POST | `/api/v1/login` | Login e retorna token | Não |
| GET | `/api/v1/me` | Dados do usuário logado | Sim |
| POST | `/api/v1/logout` | Logout | Sim |
| POST | `/api/v1/logout-all` | Logout todos dispositivos | Sim |

**Exemplo de requisição autenticada:**
```bash
curl -X GET http://localhost:8000/api/v1/me \
  -H "Authorization: Bearer 1|seu_token_aqui" \
  -H "Accept: application/json"
```

## 📚 Endpoints Principais

### Produtos - Público
| Método | Endpoint | Descrição |
| --- | --- | --- |
| GET | `/api/v1/products` | Lista produtos ativos |
| GET | `/api/v1/products/{id}` | Detalhe do produto |
| GET | `/api/v1/categories` | Lista categorias |

### Pedidos - Cliente precisa estar logado
| Método | Endpoint | Descrição |
| --- | --- | --- |
| POST | `/api/v1/orders` | Criar pedido |
| GET | `/api/v1/orders` | Meus pedidos |
| GET | `/api/v1/orders/{id}` | Detalhe do pedido |
| PATCH | `/api/v1/orders/{id}/cancel` | Cancelar pedido |

### Admin - Requer role admin
| Método | Endpoint | Descrição |
| --- | --- | --- |
| GET | `/api/v1/admin/orders` | Todos os pedidos |
| PATCH | `/api/v1/admin/orders/{id}/status` | Atualizar status |
| POST | `/api/v1/admin/products` | Criar produto |

**Exemplo: Criar Pedido**
```json
POST /api/v1/orders
{
    "delivery_type": "delivery",
    "payment_method": "cash",
    "customer_name": "João Silva",
    "customer_phone": "62999999999",
    "address": {
        "street": "Rua das Flores",
        "number": "123",
        "neighborhood": "Centro",
        "city": "Goianira",
        "zipcode": "75370-000",
        "complement": "Apto 101"
    },
    "items": [
        {
            "product_id": 1,
            "quantity": 2,
            "notes": "Sem cebola"
        }
    ],
    "notes": "Troco para R$ 50"
}
```

**Valores aceitos:**
- `delivery_type`: `delivery` ou `pickup`
- `payment_method`: `cash`, `pix`, `card_machine`

## 📁 Estrutura do Projeto

```
├── app/
│   ├── Http/Controllers/Api/    # Controllers da API
│   ├── Models/                  # Models: Product, Order, etc
│   ├── Traits/ApiResponse.php   # Padronização de respostas
│   └── Http/Resources/          # API Resources
├── docker/
│   ├── nginx/conf.d/app.conf    # Config Nginx
│   └── php/php.ini              # Config PHP
├── routes/api.php               # Rotas da API
├── Dockerfile                   # Imagem PHP 8.4
└── docker-compose.yml           # Orquestração
```

## 🧪 Testando a API

### 1. Importe a Collection do Postman
Arquivo `E-commerce-API.postman_collection.json` está na raiz do projeto.

### 2. Fluxo de teste
1. `POST /api/v1/register` - Cria usuário
2. `POST /api/v1/login` - Pega o token
3. `GET /api/v1/products` - Lista produtos
4. `POST /api/v1/orders` - Cria pedido

## 🔄 Rodando Filas e Jobs

Pra processar notificações de pedido:
```bash
docker-compose exec app php artisan queue:work
```

## 🛠️ Comandos Úteis

```bash
# Entrar no container
docker-compose exec app bash

# Rodar migrations
docker-compose exec app php artisan migrate

# Criar controller
docker-compose exec app php artisan make:controller Api/ProductController --api

# Limpar cache
docker-compose exec app php artisan optimize:clear

# Ver logs
docker-compose logs -f app
```

## 🚀 Deploy

### Variáveis de produção
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.sualoja.com.br

CACHE_DRIVER=redis
SESSION_DRIVER=redis
```

### Otimizações
```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
composer install --optimize-autoloader --no-dev
```

**Importante pra produção:**
1. Troque `DB_PASSWORD` e gere nova `APP_KEY`
2. Configure `allowed_origins` no `config/cors.php` pro domínio do seu front
3. Use HTTPS
4. Configure backup do MySQL

## 📝 Padrão de Resposta da API

**Sucesso:**
```json
{
    "success": true,
    "message": "Operação realizada com sucesso",
    "data": { ... }
}
```

**Erro:**
```json
{
    "success": false,
    "message": "Erro ao processar requisição",
    "errors": { ... }
}
```

## 🤝 Contribuindo

1. Fork o projeto
2. Crie uma branch: `git checkout -b feature/nova-feature`
3. Commit: `git commit -m 'Add nova feature'`
4. Push: `git push origin feature/nova-feature`
5. Abra um Pull Request

## 📄 Licença

Este projeto está sob licença MIT.

---
