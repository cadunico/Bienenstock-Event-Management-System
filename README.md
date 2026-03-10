# 🐝 Bienenstock — Event Management System

Note of thanks:

I thank my friend Giovani Grosso for providing the resources for the first steps of this endeavor.

[![Version](https://img.shields.io/badge/version-0.001-blue.svg)]()
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)]()
[![MySQL](https://img.shields.io/badge/MySQL-5.6%2B-orange.svg)]()
[![License](https://img.shields.io/badge/license-GPL%20v3-green.svg)]()
[![Assets](https://img.shields.io/badge/assets-CC%20BY--SA%203.0-lightgrey.svg)]()

**Standalone PHP system for complete event management: registrations, QR code check-in, schedule grids, PDF certificates and public links — no WordPress or external frameworks required.**

[Features](#-features) • [Installation](#-installation) • [Quick Start](#-quick-start) • [Modules](#-system-modules) • [Certificates](#-pdf-certificates) • [Public Links](#-public-links) • [Configuration](#-email-configuration)

---

## 📖 Table of Contents

- [English Documentation](#english-documentation)
  - [Overview](#overview)
  - [Features](#-features)
  - [Requirements](#-requirements)
  - [Installation](#-installation)
  - [Quick Start](#-quick-start)
  - [System Modules](#-system-modules)
  - [PDF Certificates](#-pdf-certificates)
  - [Public Links](#-public-links)
  - [Email Configuration](#-email-configuration)
  - [Troubleshooting](#-troubleshooting)
  - [File Structure](#-file-structure)
  - [For Developers](#-for-developers)
  - [License](#-license)
- [Documentação em Português](#documentação-em-português)

---

# English Documentation

## Overview

**Bienenstock** is an event management system built in pure PHP with MySQL, designed for organizers who need a complete, self-sufficient solution — no WordPress, Laravel, or any other framework required.

The system runs on any shared hosting with PHP 7.4+ and MySQL. The entire application is contained in a single main file (`index.php`), with support modules for specific functionality.

### Why Bienenstock?

- 🎯 **Zero dependencies**: Pure PHP + MySQL. Runs on any hosting
- 📱 **Fully Responsive**: Adapts from 4K screens down to small phones
- 🖨️ **Native PDF**: Certificate generation via GD without external libraries
- 🔒 **Secure**: Session authentication, sanitized inputs, prepared statements
- 🔗 **Public links**: Registration, check-in and schedule without login
- 🆓 **Free software**: GPL v3

---

## ✨ Features

### Participant Management
- Complete registration with customizable fields
- Status tracking: pending, confirmed, cancelled
- Individual QR code for each participant
- Webcam check-in (real-time QR scanner)
- Manual check-in by name search

### Activities & Schedule
- Activity registration with time, location, speaker and type
- Customizable activity types (Lecture, Workshop, Round Table, etc.)
- Schedule grids with multiple days/tracks
- Responsive public grid (shareable link, no login required)
- Live preview in editor

### PDF Certificates
- Visual WYSIWYG editor (800×600 px canvas, identical to PDF output)
- Two templates: Participant and Speaker
- Custom background image (recommended 800×600 px, 4:3 ratio)
- Dynamic variables: `{nome}`, `{nome_evento}`, `{data_inicio}`, `{data_fim}`, `{palestrante}`, `{atividade}`, `{horario}`
- Batch generation for all checked-in participants
- Individual download via unique public link
- Public certificate search by name

### Public Links (no login required)
- **General registration**: participant sign-up form
- **Event submission**: speakers submit their own activity data
- **Public check-in**: self check-in via QR code
- **Public schedule**: event program grid
- **Certificate**: individual search and download

### Administration
- Dashboard with real-time counters (activities, participants, check-ins, pending)
- Full CRUD management
- Unlimited custom fields (text, number, email, URL, date, time, select)
- Event settings (name, dates, SMTP)
- Responsive: off-canvas drawer on mobile with hamburger menu

---

## 💻 Requirements

| Requirement | Minimum | Recommended |
|-------------|---------|-------------|
| PHP | 7.4+ | 8.1+ |
| MySQL | 5.6+ | 8.0+ |
| GD Extension | required | with FreeType |
| PDO Extension | required | — |
| Disk space | 10 MB | 100 MB+ (uploads) |

**Required PHP extensions:**
```
pdo, pdo_mysql, gd, json, session, mbstring
```

**For TrueType font certificate rendering** (better quality):
```bash
# Debian/Ubuntu
apt-get install fonts-dejavu-core
# or
apt-get install fonts-liberation
```
If no TTF font is found, the system falls back to GD's built-in bitmap font.

---

## 🚀 Installation

### Method 1: Direct upload (recommended)

1. Download and extract `Bienenstock.zip`
2. Upload the `Bienenstock/` folder to your server (e.g. `public_html/bienenstock/`)
3. Open in browser: `https://yoursite.com/bienenstock/install.php`
4. Fill in MySQL connection details and click **Install**
5. After successful installation, **delete `install.php`**
6. Access the system at `https://yoursite.com/bienenstock/`

### Method 2: Manual setup

1. Upload the files to the server
2. Create a MySQL database
3. Run the table creation queries (available inside `install.php`)
4. Edit `config.php` with your credentials:
```php
$host = 'localhost';
$name = 'database_name';
$user = 'mysql_user';
$pass = 'mysql_password';
```
5. Access the system in the browser

### Post-installation

After installing, the system automatically creates:
- Default admin user (set during installation)
- Tables: `events`, `participants`, `certificates`, `grids`, `settings`, `field_definitions`, `users`
- Default event settings

> ⚠️ **Security**: Delete or protect `install.php` and `cert_debug.php` in production.

---

## ⚡ Quick Start

### Set up your event in 3 steps

#### Step 1: Basic settings (1 min)
1. Go to **Settings** in the sidebar
2. Fill in: event name, start and end dates
3. Configure SMTP for email sending (optional)
4. Save

#### Step 2: Add activities (2 min)
1. Go to **Activities → New Activity**
2. Fill in: title, time, location, type, speaker
3. Save
4. Repeat for all activities

#### Step 3: Create the schedule grid (1 min)
1. Go to **Grids → New Grid**
2. Give it a name (e.g. "Schedule — Day 1")
3. Add the desired activities
4. Save and copy the **public link** to share

**✅ Done!** Share the registration and grid links with participants.

---

## 🎯 System Modules

### Dashboard
Home panel with real-time counters:
- Total registered activities
- Total enrolled participants
- Total check-ins completed
- Total pending registrations
- Public links ready to copy and share

### Activities
Manage the full event program:

| Field | Description |
|-------|-------------|
| Title | Activity name |
| Type | Lecture, Workshop, Round Table, etc. |
| Start/end time | HH:MM format |
| Location | Room, auditorium or online link |
| Speaker | Person responsible |
| Description | Activity details |

### Participants
Full attendee control:
- **Registration**: name, email, customizable extra fields
- **Status**: pending → confirmed → cancelled
- **QR Code**: automatically generated for each participant
- **Search**: by name or email in real time

### Check-in
Two operating modes:
- **Webcam**: real-time QR code scanner (requires camera and HTTPS)
- **Manual**: name search with click confirmation

### Grids
Organize the schedule by days or tracks:
- Multiple grids per event
- Visual activity selection interface
- Individual public link per grid
- Responsive table display

### Activity Types
Categorize your activities:
- Default types: Lecture, Workshop, Round Table
- Create custom types with name and color
- Displayed as colored badges in the public grid

### Custom Fields
Add extra fields to the registration form:

| Type | Description |
|------|-------------|
| `text` | Single line |
| `textarea` | Multiple lines |
| `number` | Numeric |
| `email` | Email with validation |
| `url` | Link/URL |
| `date` | Date picker |
| `time` | Time picker |
| `select` | Dropdown list |

Each field can be marked as required and visible in the public grid.

---

## 🖨️ PDF Certificates

The certificate module generates PDFs directly on the server using PHP's GD extension — no external dependencies.

### How it works

The visual editor uses an **800×600 px** canvas that is exactly the same size used to render the PDF. What you see in the editor is what gets printed.

```
Visual editor (browser)   →   GD canvas (PHP)   →   A4 landscape PDF
      800×600 px          =      800×600 px      →   scaled proportionally
```

### Setting up the template

1. Go to **Certificates** in the menu
2. Choose the type: **Participation** (for attendees) or **Activity** (for speakers)
3. Click **Edit Template**

In the visual editor you can:
- **Drag** the text block using the ↑↓←→ buttons
- **Format** text: bold, italic, underline
- **Align**: left, center, right
- **Font size**: 10px to 64px
- **Text color**: color picker
- **Background**: upload image (recommended **800×600 px**, 4:3 ratio)

### Available variables

| Variable | Replaced by |
|----------|-------------|
| `{nome}` | Participant name |
| `{nome_evento}` | Event name (from Settings) |
| `{data_inicio}` | Start date (dd/mm/yyyy) |
| `{data_fim}` | End date (dd/mm/yyyy) |
| `{palestrante}` | Speaker name (Activity template) |
| `{atividade}` | Activity title (Activity template) |
| `{horario}` | Activity time (Activity template) |

### Generation and download

- **Individual**: each participant accesses their certificate via a unique public link
- **Batch**: administrator downloads all PDFs at once
- **Public search**: name-based search page, no login required

### Requirements for best quality

The system uses TrueType fonts if available on the server:

```
# Font priority (first available is used):
DejaVuSans.ttf
LiberationSans-Regular.ttf
FreeSans.ttf
Ubuntu-R.ttf
NotoSans-Regular.ttf
```

If no TTF font is found, the system uses GD's built-in bitmap font (lower quality, but functional).

---

## 🔗 Public Links

The system provides 5 public links that work **without login**:

### 1. General Registration
```
https://yoursite.com/bienenstock/index.php?p=public
```
Participant sign-up form including all required and custom fields configured in **Fields**.

### 2. Event Submission (Speaker)
```
https://yoursite.com/bienenstock/public-links.php?p=submit_event
```
Allows speakers to submit their own activity data. After submission, the admin reviews and publishes.

### 3. Public Check-in
```
https://yoursite.com/bienenstock/index.php?p=checkin
```
Participants check in by showing their QR code (or the admin scans via webcam).

### 4. Public Schedule Grid
Each grid has its own link:
```
https://yoursite.com/bienenstock/public-links.php?p=grid&id={GRID_ID}
```
Find the link at **Grids → Copy public link**.

### 5. Certificate Search
```
https://yoursite.com/bienenstock/public-links.php?p=cert_search
```
Participants search and download their own certificate by name.

---

## 🔧 Email Configuration

The system uses SMTP for email sending. Configure in **Settings**:

| Field | Description |
|-------|-------------|
| SMTP Host | e.g. `smtp.gmail.com` |
| Port | `587` (TLS) or `465` (SSL) |
| User | Sender email |
| Password | Password or App Password |
| Sender name | e.g. "Event Organization" |

**To test**: access `email_test.php` in the browser after configuring.

### Gmail
Use an **App Password** (not your regular password):
1. Enable 2-step verification on your Google account
2. Go to: Google Account → Security → App passwords
3. Create a password for "Mail / Other"
4. Use that password in the SMTP Password field

---

## 🐛 Troubleshooting

### Certificate not generating

**Check GD extension:**
```php
<?php phpinfo(); // look for "gd" on the page
```

**Automatic diagnostics** — open in browser:
```
https://yoursite.com/bienenstock/cert_debug.php?secret=debug2024
```
> ⚠️ Delete or protect this file in production!

The diagnostic shows: GD/FreeType status, available TTF fonts, stored HTML with alignment per line, and a PDF generation test result.

### Webcam check-in not working

The QR webcam scanner requires **HTTPS**. In local environments, use:
- `localhost` (browsers usually allow camera access)
- Self-signed SSL certificate
- A tunnel like ngrok for testing

### Grid not showing

- Check that the grid has activities added
- Confirm that activities are saved
- Clear browser cache

### Strange characters in PDF

The system already handles Portuguese accents. If issues persist:
- Verify the database is using `utf8mb4`
- Confirm PHP is processing the file as UTF-8

### Email not sending

1. Access `email_test.php` to test the SMTP connection
2. Check if the port is blocked by your hosting provider
3. For Gmail: confirm you are using an App Password
4. Some hosting providers block outbound SMTP — contact their support

---

## 📁 File Structure

```
Bienenstock/
│
├── index.php           # Main application — all admin modules
├── public-links.php    # Public pages (registration, grid, certificate)
├── cert_pdf.php        # PDF certificate generation engine
├── smtp_mailer.php     # SMTP email sending
├── install.php         # Web installer (delete after use)
├── cert_debug.php      # Certificate diagnostics (delete in production)
├── email_test.php      # SMTP email test
├── logo.svg            # System logo
└── .htaccess           # Sensitive file protection
```

> `config.php` is created automatically by `install.php`.

Uploaded files (certificate background images) are stored in:
```
Bienenstock/uploads/certs/
```

---

## 👨‍💻 For Developers

### Adding a new admin page

Routing is done via the `?p=` parameter in `index.php`:

```php
if ($p === 'my_page') {
    html_start('My Page');
    // your content here
    html_end();
}
```

### Database structure

| Table | Description |
|-------|-------------|
| `users` | Admin users |
| `events` | Activities/talks |
| `participants` | Event registrants |
| `grids` | Schedule grids |
| `certificates` | Certificate templates |
| `settings` | General event settings |
| `field_definitions` | Custom fields |

### Session variables

```php
$_SESSION['user_id']    // ID of logged-in user
$_SESSION['user_name']  // User name
$_SESSION['user_email'] // User email
```

---

## 📄 License

**PHP/JS/CSS code:** [GPL v3](https://www.gnu.org/licenses/gpl-3.0.html)  
**Visual assets (logo, icons):** [CC BY-SA 3.0](https://creativecommons.org/licenses/by-sa/3.0/)

---

## 🌟 Credits

**Developed by:** [Carlos Eduardo Mattos da Cruz (cadunico)](https://github.com/cadunico)

**Built with:** PHP 7.4+ (no frameworks) · MySQL / PDO · GD Library · jsQR · CSS Grid + Flexbox

**Repository:** [github.com/cadunico/bienenstock-event-manager](https://github.com/cadunico/bienenstock-event-manager)

---

**Made with ❤️ — Free software for the community**

[⬆ Back to top](#-bienenstock--event-management-system)

---
---
---

# Documentação em Português

**Sistema standalone PHP para gestão completa de eventos: inscrições, check-in por QR code, grades de programação, certificados PDF e links públicos — sem depender de WordPress ou frameworks externos.**

Nota de agradecimento:

Agradeço ao meu amigo Giovani Grosso por ter proporcionado os recursos para os primeiros passos desta empreitada.

---

## 📖 Índice

- [Visão Geral](#visão-geral)
- [Funcionalidades](#-funcionalidades-1)
- [Requisitos](#-requisitos-1)
- [Instalação](#-instalação-1)
- [Início Rápido](#-início-rápido)
- [Módulos do Sistema](#-módulos-do-sistema)
- [Certificados PDF](#-certificados-pdf)
- [Links Públicos](#-links-públicos)
- [Configuração de E-mail](#-configuração-de-e-mail)
- [Solução de Problemas](#-solução-de-problemas)
- [Estrutura de Arquivos](#-estrutura-de-arquivos)
- [Para Desenvolvedores](#-para-desenvolvedores)
- [Licença](#-licença)

---

## Visão Geral

O **Bienenstock** é um sistema de gestão de eventos desenvolvido em PHP puro com banco MySQL, criado para organizadores que precisam de uma solução completa e autossuficiente — sem depender de WordPress, Laravel, ou qualquer outro framework.

O sistema roda em qualquer hospedagem compartilhada com PHP 7.4+ e MySQL. Toda a aplicação está contida em um único arquivo principal (`index.php`), com módulos de suporte para funcionalidades específicas.

### Por que Bienenstock?

- 🎯 **Zero dependências**: PHP puro + MySQL. Funciona em qualquer hospedagem
- 📱 **100% Responsivo**: Interface adaptada de telas 4K a celulares pequenos
- 🖨️ **PDF nativo**: Geração de certificados via GD sem bibliotecas externas
- 🔒 **Seguro**: Autenticação por sessão, inputs sanitizados, prepared statements
- 🔗 **Links públicos**: Inscrição, check-in e grade sem login
- 🆓 **Software livre**: GPL v3

---

## ✨ Funcionalidades

### Gestão de Participantes
- Cadastro completo com campos personalizáveis
- Status: pendente, confirmado, cancelado
- QR code individual para cada participante
- Check-in por webcam (scanner de QR em tempo real)
- Check-in manual por busca de nome

### Atividades e Grade
- Cadastro de atividades com horário, local, palestrante e tipo
- Tipos de atividade personalizáveis (Palestra, Oficina, Mesa Redonda, etc.)
- Grades de programação com múltiplos dias/trilhas
- Grade pública responsiva (link compartilhável sem login)
- Preview ao vivo na edição

### Certificados PDF
- Editor visual WYSIWYG (800×600 px, canvas idêntico ao PDF)
- Dois templates: Participante e Palestrante
- Imagem de fundo personalizada (recomendado 800×600 px, proporção 4:3)
- Variáveis dinâmicas: `{nome}`, `{nome_evento}`, `{data_inicio}`, `{data_fim}`, `{palestrante}`, `{atividade}`, `{horario}`
- Geração em lote para todos os participantes com check-in
- Download individual via link público único
- Busca pública de certificados por nome

### Links Públicos (sem login)
- **Inscrição geral**: formulário de inscrição para participantes
- **Submissão de evento**: palestrantes submetem dados da própria atividade
- **Check-in público**: auto check-in via QR code
- **Grade pública**: programação do evento
- **Certificado**: busca e download individual

### Administração
- Dashboard com totalizadores (atividades, participantes, check-ins, pendentes)
- Gerenciamento completo via CRUD
- Campos personalizados ilimitados (texto, número, email, URL, data, hora, select)
- Configurações do evento (nome, datas, SMTP)
- Responsivo: sidebar drawer em mobile, menu hamburger

---

## 💻 Requisitos

| Requisito | Mínimo | Recomendado |
|-----------|--------|-------------|
| PHP | 7.4+ | 8.1+ |
| MySQL | 5.6+ | 8.0+ |
| Extensão GD | obrigatória | com FreeType |
| Extensão PDO | obrigatória | — |
| Espaço em disco | 10 MB | 100 MB+ (uploads) |

**Extensões PHP necessárias:**
```
pdo, pdo_mysql, gd, json, session, mbstring
```

**Para geração de certificados com fonte TrueType** (melhor qualidade):
```bash
# Debian/Ubuntu
apt-get install fonts-dejavu-core
# ou
apt-get install fonts-liberation
```
Se nenhuma fonte TTF for encontrada, o sistema usa a fonte GD embutida como fallback.

---

## 🚀 Instalação

### Método 1: Upload direto (recomendado)

1. Baixe e extraia o arquivo `Bienenstock.zip`
2. Faça upload da pasta `Bienenstock/` para o seu servidor (ex: `public_html/bienenstock/`)
3. Abra no navegador: `https://seusite.com/bienenstock/install.php`
4. Preencha os dados de conexão MySQL e clique em **Instalar**
5. Após instalação bem-sucedida, **delete o arquivo `install.php`**
6. Acesse o sistema em `https://seusite.com/bienenstock/`

### Método 2: Manual via banco de dados

1. Faça upload dos arquivos para o servidor
2. Crie um banco de dados MySQL
3. Execute as queries de criação de tabelas (disponíveis em `install.php`)
4. Edite o arquivo `config.php` com suas credenciais:
```php
$host = 'localhost';
$name = 'nome_do_banco';
$user = 'usuario_mysql';
$pass = 'senha_mysql';
```
5. Acesse o sistema no navegador

### Pós-instalação

Após instalar, o sistema cria automaticamente:
- Usuário administrador padrão (definido durante a instalação)
- Tabelas: `events`, `participants`, `certificates`, `grids`, `settings`, `field_definitions`, `users`
- Configurações padrão do evento

> ⚠️ **Segurança**: Delete ou proteja `install.php` e `cert_debug.php` em produção.

---

## ⚡ Início Rápido

### Configure o evento em 3 passos

#### Passo 1: Configurações básicas (1 min)
1. Acesse **Configurações** no menu lateral
2. Preencha: nome do evento, datas de início e fim
3. Configure SMTP se quiser envio de e-mails (opcional)
4. Salve

#### Passo 2: Adicione atividades (2 min)
1. Acesse **Atividades → Nova Atividade**
2. Preencha: título, horário, local, tipo, palestrante
3. Salve
4. Repita para todas as atividades

#### Passo 3: Crie a grade de programação (1 min)
1. Acesse **Grades → Nova Grade**
2. Dê um nome (ex: "Programação — Dia 1")
3. Adicione as atividades desejadas
4. Salve e copie o **link público** para compartilhar

**✅ Pronto!** Compartilhe os links de inscrição e grade com os participantes.

---

## 🎯 Módulos do Sistema

### Dashboard
Painel inicial com totalizadores em tempo real:
- Total de atividades cadastradas
- Total de participantes inscritos
- Total de check-ins realizados
- Total de inscrições pendentes
- Links públicos prontos para copiar e compartilhar

### Atividades
Gerencie toda a programação do evento:

| Campo | Descrição |
|-------|-----------|
| Título | Nome da atividade |
| Tipo | Palestra, Oficina, Mesa Redonda, etc. |
| Horário início/fim | Formato HH:MM |
| Local | Sala, auditório ou link online |
| Palestrante | Nome do responsável |
| Descrição | Detalhes da atividade |

### Participantes
Controle completo dos inscritos:
- **Cadastro**: nome, e-mail, campos extras personalizáveis
- **Status**: pendente → confirmado → cancelado
- **QR Code**: gerado automaticamente para cada participante
- **Busca**: por nome ou e-mail em tempo real

### Check-in
Dois modos de operação:
- **Webcam**: scanner de QR code em tempo real (requer câmera e HTTPS)
- **Manual**: busca por nome com confirmação por clique

### Grades
Organize a programação por dias ou trilhas:
- Múltiplas grades por evento
- Seleção visual de atividades
- Link público individual para cada grade
- Exibição responsiva em tabela

### Tipos de Atividade
Categorize suas atividades:
- Tipos padrão: Palestra, Oficina, Mesa Redonda
- Crie tipos personalizados com nome e cor
- Exibidos como badges coloridos na grade pública

### Campos Personalizados
Adicione campos extras ao formulário de inscrição:

| Tipo | Descrição |
|------|-----------|
| `text` | Linha única |
| `textarea` | Múltiplas linhas |
| `number` | Numérico |
| `email` | E-mail com validação |
| `url` | Link/URL |
| `date` | Data (seletor) |
| `time` | Horário |
| `select` | Lista suspensa |

Cada campo pode ser marcado como obrigatório e visível na grade pública.

---

## 🖨️ Certificados PDF

O módulo de certificados gera PDFs diretamente no servidor usando a extensão GD do PHP — sem dependências externas.

### Como funciona

O editor visual usa um canvas de **800×600 px** que é exatamente o mesmo tamanho usado para renderizar o PDF. O que você vê no editor é o que sai impresso.

```
Editor visual (navegador)     →    GD canvas (PHP)    →    PDF A4 paisagem
      800×600 px              =       800×600 px       →    escalonado proporcionalmente
```

### Configurando o template

1. Acesse **Certificados** no menu
2. Escolha o tipo: **Participação** (para inscritos) ou **Atividade** (para palestrantes)
3. Clique em **Editar Template**

No editor visual você pode:
- **Arrastar** o bloco de texto com os botões ↑↓←→
- **Formatar** o texto: negrito, itálico, sublinhado
- **Alinhar**: esquerda, centro, direita
- **Tamanho da fonte**: 10px a 64px
- **Cor do texto**: seletor de cor
- **Fundo**: upload de imagem (recomendado **800×600 px**, proporção 4:3)

### Variáveis disponíveis

| Variável | Substitui por |
|----------|---------------|
| `{nome}` | Nome do participante |
| `{nome_evento}` | Nome do evento (das Configurações) |
| `{data_inicio}` | Data de início (dd/mm/aaaa) |
| `{data_fim}` | Data de término (dd/mm/aaaa) |
| `{palestrante}` | Nome do palestrante (template Atividade) |
| `{atividade}` | Título da atividade (template Atividade) |
| `{horario}` | Horário da atividade (template Atividade) |

### Geração e download

- **Individual**: cada participante acessa seu certificado via link público único
- **Em lote**: administrador baixa todos os PDFs de uma vez
- **Busca pública**: página de busca por nome, sem login

### Requisitos para melhor qualidade

O sistema usa fontes TrueType se disponíveis no servidor:

```
# Prioridade de fontes (primeira disponível é usada):
DejaVuSans.ttf
LiberationSans-Regular.ttf
FreeSans.ttf
Ubuntu-R.ttf
NotoSans-Regular.ttf
```

Se nenhuma fonte TTF for encontrada, o sistema usa a fonte bitmap embutida do GD (qualidade menor, mas funcional).

---

## 🔗 Links Públicos

O sistema oferece 5 links públicos que funcionam **sem login**:

### 1. Inscrição Geral
```
https://seusite.com/bienenstock/index.php?p=public
```
Formulário de inscrição para participantes, com todos os campos obrigatórios e personalizados.

### 2. Submissão de Evento (Palestrante)
```
https://seusite.com/bienenstock/public-links.php?p=submit_event
```
Permite que palestrantes submetam os dados da própria atividade. O administrador revisa e publica.

### 3. Check-in Público
```
https://seusite.com/bienenstock/index.php?p=checkin
```
Participantes fazem check-in mostrando o QR code (ou o administrador escaneia pela webcam).

### 4. Grade Pública
Cada grade tem seu próprio link:
```
https://seusite.com/bienenstock/public-links.php?p=grid&id={ID_DA_GRADE}
```
Encontre o link em **Grades → Copiar link público**.

### 5. Busca de Certificado
```
https://seusite.com/bienenstock/public-links.php?p=cert_search
```
Participantes buscam e baixam o próprio certificado pelo nome.

---

## 🔧 Configuração de E-mail

O sistema usa SMTP para envio de e-mails. Configure em **Configurações**:

| Campo | Descrição |
|-------|-----------|
| Host SMTP | Ex: `smtp.gmail.com` |
| Porta | `587` (TLS) ou `465` (SSL) |
| Usuário | E-mail remetente |
| Senha | Senha ou App Password |
| Nome remetente | Ex: "Organização do Evento" |

**Para testar**: acesse `email_test.php` no navegador após configurar.

### Gmail
Use uma **Senha de App** (não a senha normal):
1. Ative verificação em 2 etapas na conta Google
2. Acesse: Conta Google → Segurança → Senhas de app
3. Crie uma senha para "E-mail / Outro"
4. Use essa senha no campo Senha SMTP

---

## 🐛 Solução de Problemas

### Certificado não gera

**Verificar extensão GD:**
```php
<?php phpinfo(); // procure por "gd" na página
```

**Diagnóstico automático** — acesse no navegador:
```
https://seusite.com/bienenstock/cert_debug.php?secret=debug2024
```
> ⚠️ Delete ou proteja este arquivo em produção!

O diagnóstico mostra: status da extensão GD/FreeType, fontes TTF disponíveis, HTML salvo com alinhamentos por linha e resultado do teste de geração de PDF.

### Check-in por webcam não funciona

O scanner de QR por webcam requer **HTTPS**. Em ambiente local, use:
- `localhost` (browsers geralmente permitem câmera)
- Certificado SSL autoassinado
- Túnel como ngrok para testes

### Grade não aparece

- Verifique se a grade tem atividades adicionadas
- Confirme que as atividades estão salvas
- Limpe o cache do navegador

### Caracteres estranhos no PDF

O sistema já trata acentos do português. Se ainda aparecer problema:
- Verifique se o banco está em `utf8mb4`
- Confirme que o PHP está processando o arquivo em UTF-8

### E-mail não envia

1. Acesse `email_test.php` para testar a conexão SMTP
2. Verifique se a porta não está bloqueada pelo provedor
3. Para Gmail: confirme que está usando Senha de App
4. Alguns provedores de hospedagem bloqueiam SMTP externo — contate o suporte

---

## 📁 Estrutura de Arquivos

```
Bienenstock/
│
├── index.php           # Aplicação principal — todos os módulos admin
├── public-links.php    # Páginas públicas (inscrição, grade, certificado)
├── cert_pdf.php        # Motor de geração de certificados PDF
├── smtp_mailer.php     # Envio de e-mails via SMTP
├── install.php         # Instalador web (delete após uso)
├── cert_debug.php      # Diagnóstico de certificados (delete em produção)
├── email_test.php      # Teste de e-mail SMTP
├── logo.svg            # Logotipo do sistema
└── .htaccess           # Proteção de arquivos sensíveis
```

> O arquivo `config.php` é criado automaticamente pelo `install.php`.

Arquivos enviados (imagens de fundo dos certificados) ficam em:
```
Bienenstock/uploads/certs/
```

---

## 👨‍💻 Para Desenvolvedores

### Adicionando uma nova página admin

O roteamento é feito pelo parâmetro `?p=` em `index.php`:

```php
if ($p === 'minha_pagina') {
    html_start('Minha Página');
    // seu conteúdo aqui
    html_end();
}
```

### Estrutura do banco de dados

| Tabela | Descrição |
|--------|-----------|
| `users` | Usuários administradores |
| `events` | Atividades/palestras |
| `participants` | Inscritos no evento |
| `grids` | Grades de programação |
| `certificates` | Templates de certificados |
| `settings` | Configurações gerais do evento |
| `field_definitions` | Campos personalizados |

### Variáveis de sessão

```php
$_SESSION['user_id']    // ID do usuário logado
$_SESSION['user_name']  // Nome do usuário
$_SESSION['user_email'] // E-mail do usuário
```

---

## 📄 Licença

**Código PHP/JS/CSS:** [GPL v3](https://www.gnu.org/licenses/gpl-3.0.html)  
**Assets visuais (logo, ícones):** [CC BY-SA 3.0](https://creativecommons.org/licenses/by-sa/3.0/)

---

## 🌟 Créditos

**Desenvolvido por:** [Carlos Eduardo Mattos da Cruz (cadunico)](https://github.com/cadunico)

**Construído com:** PHP 7.4+ (sem frameworks) · MySQL / PDO · GD Library · jsQR · CSS Grid + Flexbox

**Repositório:** [github.com/cadunico/bienenstock-event-manager](https://github.com/cadunico/bienenstock-event-manager)

---

**Feito com ❤️ — Software livre para a comunidade**

[⬆ Voltar ao topo](#-bienenstock--event-management-system)
