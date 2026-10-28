# -*- coding: utf-8 -*-
"""生成《智能客服 Web 系统的设计与实现》实验报告 docx

注意：这是“基础版报告”的生成脚本。正式交付的 docx 已在此基础上：
  ① 用 tools/fill_report_cover.py 填了封面个人信息；
  ② 按本机实测环境修订过正文口径（PHP 8.0.30 + MariaDB 10.4、星火实测结论等）。
直接重跑本脚本会用基础版覆盖正式版，只在需要大改结构时使用。
"""
import os
from docx import Document
from docx.shared import Pt, Cm, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_LINE_SPACING
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.enum.section import WD_SECTION
from docx.oxml.ns import qn
from docx.oxml import OxmlElement

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SHOTS = os.path.join(ROOT, 'shots')
OUT = os.path.join(ROOT, '实验报告-智能客服系统.docx')

FONT_CN = '宋体'
FONT_HEI = '黑体'
FONT_EN = 'Times New Roman'
FONT_CODE = 'Consolas'

doc = Document()

# ---------- 页面设置 ----------
sec = doc.sections[0]
sec.page_width = Cm(21)
sec.page_height = Cm(29.7)
sec.top_margin = Cm(2.4)
sec.bottom_margin = Cm(2.2)
sec.left_margin = Cm(2.6)
sec.right_margin = Cm(2.4)


def set_run_font(run, name_cn=FONT_CN, size=12, bold=False, color=None, name_en=FONT_EN):
    run.font.name = name_en
    run.font.size = Pt(size)
    run.bold = bold
    if color:
        run.font.color.rgb = color
    rpr = run._element.get_or_add_rPr()
    rfonts = rpr.find(qn('w:rFonts'))
    if rfonts is None:
        rfonts = OxmlElement('w:rFonts')
        rpr.append(rfonts)
    rfonts.set(qn('w:eastAsia'), name_cn)
    rfonts.set(qn('w:ascii'), name_en)
    rfonts.set(qn('w:hAnsi'), name_en)


def style_font(style, name_cn, size, bold=False, color=RGBColor(0, 0, 0)):
    style.font.name = FONT_EN
    style.font.size = Pt(size)
    style.font.bold = bold
    style.font.color.rgb = color
    rpr = style.element.get_or_add_rPr()
    rfonts = rpr.find(qn('w:rFonts'))
    if rfonts is None:
        rfonts = OxmlElement('w:rFonts')
        rpr.append(rfonts)
    rfonts.set(qn('w:eastAsia'), name_cn)


normal = doc.styles['Normal']
style_font(normal, FONT_CN, 12)
normal.paragraph_format.line_spacing = 1.5
normal.paragraph_format.space_after = Pt(3)

for level, size in ((1, 16), (2, 14), (3, 12)):
    st = doc.styles[f'Heading {level}']
    style_font(st, FONT_HEI, size, bold=True)
    st.paragraph_format.space_before = Pt(12 if level == 1 else 8)
    st.paragraph_format.space_after = Pt(6 if level == 1 else 4)
    st.paragraph_format.line_spacing = 1.3
    st.paragraph_format.keep_with_next = True


def p(text='', indent=True, size=12, bold=False, align=None, space_after=3, color=None):
    par = doc.add_paragraph()
    if indent:
        par.paragraph_format.first_line_indent = Pt(size * 2)
    par.paragraph_format.space_after = Pt(space_after)
    if align:
        par.alignment = align
    run = par.add_run(text)
    set_run_font(run, FONT_CN, size, bold, color)
    return par


def h1(text):
    doc.add_heading(text, level=1)


def h2(text):
    doc.add_heading(text, level=2)


def h3(text):
    doc.add_heading(text, level=3)


def bullet(text, size=12):
    par = doc.add_paragraph(style='List Bullet')
    par.paragraph_format.line_spacing = 1.5
    par.paragraph_format.space_after = Pt(2)
    run = par.add_run(text)
    set_run_font(run, FONT_CN, size)
    return par


def caption(text):
    par = doc.add_paragraph()
    par.alignment = WD_ALIGN_PARAGRAPH.CENTER
    par.paragraph_format.space_before = Pt(2)
    par.paragraph_format.space_after = Pt(8)
    run = par.add_run(text)
    set_run_font(run, FONT_CN, 10.5, color=RGBColor(0x55, 0x5F, 0x73))


def img(name, cap, width=15.0):
    path = os.path.join(SHOTS, name + '.png')
    if not os.path.exists(path):
        p(f'[缺少截图 {name}]')
        return
    par = doc.add_paragraph()
    par.alignment = WD_ALIGN_PARAGRAPH.CENTER
    par.paragraph_format.space_before = Pt(6)
    run = par.add_run()
    run.add_picture(path, width=Cm(width))
    caption(cap)


def shade(par, color='F4F6FB'):
    ppr = par._p.get_or_add_pPr()
    shd = OxmlElement('w:shd')
    shd.set(qn('w:val'), 'clear')
    shd.set(qn('w:color'), 'auto')
    shd.set(qn('w:fill'), color)
    ppr.append(shd)


def code(lines):
    for line in lines:
        par = doc.add_paragraph()
        par.paragraph_format.space_after = Pt(0)
        par.paragraph_format.line_spacing = 1.05
        par.paragraph_format.left_indent = Cm(0.3)
        shade(par)
        run = par.add_run(line if line else ' ')
        set_run_font(run, FONT_CN, 8.5, name_en=FONT_CODE)


def table(headers, rows, widths=None, font_size=10):
    t = doc.add_table(rows=1, cols=len(headers))
    t.style = 'Table Grid'
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    hdr = t.rows[0].cells
    for i, h in enumerate(headers):
        hdr[i].text = ''
        par = hdr[i].paragraphs[0]
        par.alignment = WD_ALIGN_PARAGRAPH.CENTER
        run = par.add_run(h)
        set_run_font(run, FONT_HEI, font_size, bold=True)
        shd = OxmlElement('w:shd')
        shd.set(qn('w:val'), 'clear')
        shd.set(qn('w:color'), 'auto')
        shd.set(qn('w:fill'), 'EEF0FF')
        hdr[i]._tc.get_or_add_tcPr().append(shd)
    for row in rows:
        cells = t.add_row().cells
        for i, val in enumerate(row):
            cells[i].text = ''
            par = cells[i].paragraphs[0]
            par.paragraph_format.line_spacing = 1.15
            run = par.add_run(str(val))
            set_run_font(run, FONT_CN, font_size)
    if widths:
        for i, w in enumerate(widths):
            for row in t.rows:
                row.cells[i].width = Cm(w)
    return t


def add_page_number_footer(section):
    footer = section.footer
    par = footer.paragraphs[0]
    par.alignment = WD_ALIGN_PARAGRAPH.CENTER

    def field(par, instr):
        r = par.add_run()
        fld_begin = OxmlElement('w:fldChar')
        fld_begin.set(qn('w:fldCharType'), 'begin')
        r._r.append(fld_begin)
        r2 = par.add_run()
        it = OxmlElement('w:instrText')
        it.set(qn('xml:space'), 'preserve')
        it.text = instr
        r2._r.append(it)
        r3 = par.add_run()
        fld_end = OxmlElement('w:fldChar')
        fld_end.set(qn('w:fldCharType'), 'end')
        r3._r.append(fld_end)

    run = par.add_run('第 ')
    set_run_font(run, FONT_CN, 9)
    field(par, 'PAGE')
    run2 = par.add_run(' 页')
    set_run_font(run2, FONT_CN, 9)


add_page_number_footer(sec)

# ============================== 封面 ==============================
for _ in range(2):
    doc.add_paragraph()

par = doc.add_paragraph()
par.alignment = WD_ALIGN_PARAGRAPH.CENTER
run = par.add_run('东莞理工学院计算机科学与技术学院')
set_run_font(run, FONT_HEI, 15, bold=True)

par = doc.add_paragraph()
par.alignment = WD_ALIGN_PARAGRAPH.CENTER
par.paragraph_format.space_before = Pt(10)
run = par.add_run('实 验 报 告')
set_run_font(run, FONT_HEI, 30, bold=True)

par = doc.add_paragraph()
par.alignment = WD_ALIGN_PARAGRAPH.CENTER
par.paragraph_format.space_before = Pt(20)
run = par.add_run('课程名称：PHP 程序设计        学期：2026 年秋季')
set_run_font(run, FONT_CN, 12)

doc.add_paragraph()
doc.add_paragraph()

# 封面信息表
cover = doc.add_table(rows=4, cols=4)
cover.style = 'Table Grid'
cover.alignment = WD_TABLE_ALIGNMENT.CENTER
cover_data = [
    ('项目题目', '智能客服 Web 系统的设计与实现', '实验序号', '综合设计'),
    ('姓    名', '***', '学    号', '***'),
    ('班    级', '***', '实验地点', '***'),
    ('实验日期', '2026-10-27', '指导老师', '罗凯伦'),
]
for r, row in enumerate(cover_data, start=0):
    for c, val in enumerate(row):
        cell = cover.cell(r, c)
        cell.text = ''
        par = cell.paragraphs[0]
        par.alignment = WD_ALIGN_PARAGRAPH.CENTER
        run = par.add_run(val)
        set_run_font(run, FONT_HEI if c % 2 == 0 else FONT_CN, 11, bold=(c % 2 == 0))
        cell.vertical_alignment = 1

# 合并题目内容单元格
cover.cell(0, 1).merge(cover.cell(0, 1))
for row in cover.rows:
    row.height = Cm(1.1)
cover.columns[0].width = Cm(3.2)
cover.columns[1].width = Cm(6.8)
cover.columns[2].width = Cm(3.2)
cover.columns[3].width = Cm(3.6)

doc.add_paragraph()

# 教师评语
par = doc.add_paragraph()
par.paragraph_format.space_before = Pt(8)
run = par.add_run('教师评语：')
set_run_font(run, FONT_HEI, 11, bold=True)
for _ in range(3):
    par = doc.add_paragraph('　')
    par.paragraph_format.space_after = Pt(6)

par = doc.add_paragraph()
par.alignment = WD_ALIGN_PARAGRAPH.RIGHT
run = par.add_run('评阅老师：罗凯伦        成绩（百分制）：__________')
set_run_font(run, FONT_CN, 11)

doc.add_page_break()

# ============================== 目录 ==============================
h1('目  录')
toc = [
    ('1  实验目标', ''),
    ('2  实验条件', ''),
    ('3  实验内容与需求分析', ''),
    ('    3.1  功能需求', ''),
    ('    3.2  非功能需求', ''),
    ('4  系统设计', ''),
    ('    4.1  总体架构', ''),
    ('    4.2  数据库设计', ''),
    ('    4.3  权限与安全设计', ''),
    ('    4.4  智能客服流程设计', ''),
    ('    4.5  创新功能设计', ''),
    ('5  实验步骤', ''),
    ('6  实验结果', ''),
    ('7  关键问题与解决', ''),
    ('8  总结与心得', ''),
    ('附录 A  关键代码', ''),
    ('附录 B  运行说明', ''),
]
for item, _ in toc:
    par = doc.add_paragraph()
    par.paragraph_format.space_after = Pt(4)
    run = par.add_run(item)
    set_run_font(run, FONT_CN, 12)

doc.add_page_break()

# ============================== 正文 ==============================
h1('1  实验目标')
p('本实验以“智能 web 系统设计与实现”为主题，要求综合运用 PHP 前端与后端技术，调用大语言模型 API，构建一个具有一定创新性的智能客服 Web 应用。具体目标包括：')
bullet('理解大语言模型 API 的调用方式，掌握鉴权、消息组织、流式响应解析等关键环节；')
bullet('综合运用数据库操作、文件操作、正则表达式、页面重定向、Session 与 AJAX 等 PHP 知识；')
bullet('完成用户管理、权限管理、日志管理、三种咨询方式与统计可视化等业务模块；')
bullet('在满足基本要求的基础上，设计一个有实际价值的创新功能，并完成系统测试与实验报告。')

h1('2  实验条件')
table(
    ['项目', '配置'],
    [
        ['操作系统', 'Windows 11（64 位）'],
        ['运行环境', 'XAMPP（Apache 2.4.58 + PHP 8.0.30）'],
        ['PHP 扩展', 'pdo_mysql、pdo_sqlite、mysqli、mbstring、openssl、curl、json'],
        ['数据库', 'MariaDB 10.4.32（XAMPP 自带，已实测）；SQLite 3（零配置备选）'],
        ['开发工具', 'VS Code、Chrome 浏览器、phpMyAdmin'],
        ['大模型', '讯飞星火大模型（WebSocket API，v3.5），实验环境未配置密钥时使用本地演示引擎'],
        ['辅助资料', '《指导手册》《综合设计-智能web系统设计与实现》课程课件'],
    ],
    widths=[4.0, 12.0],
)
p('说明：验收演示默认使用本地演示引擎完成全部交互（星火客户端已按官方 WebSocket 协议完整实现，未配置密钥时自动降级；本机已实测可连通讯飞接口并通过 TLS 证书校验）。在 config.local.php 中填入 APPID / APIKey / APISecret 后即可切换为真实大模型。', indent=True, size=10.5)

h1('3  实验内容与需求分析')

h2('3.1  功能需求')
p('系统面向校园服务场景，设置普通用户、客服专员、管理员三种角色。核心功能与实验节点的对应关系如下：')
table(
    ['实验节点', '功能要求', '本系统实现'],
    [
        ['节点一 UI 布局', '选择咨询方式、查看历史、用户管理界面', '登录/注册、工作台、聊天页、历史页、个人中心，响应式布局'],
        ['节点二 用户管理', '用户信息维护', '注册登录、资料与密码修改；管理员增删改查、重置密码、停用账号'],
        ['节点三 权限管理', '不同角色权限隔离', 'admin / agent / user 三种角色，页面与接口双重校验，越权返回 403 并记日志'],
        ['节点四 日志管理', '操作留痕、可查询', '登录、咨询、工单、后台操作双写入库与文件，支持正则筛选、CSV 导出'],
        ['节点五 咨询方式', '单轮、多轮、信息选择', '三种方式统一入口：独立问答、带上下文对话、类别→问题→推荐步骤'],
        ['节点六 可视化', '历史交互数据可视化', '7 日趋势、方式占比、满意度、工单状态、热门主题五张图'],
    ],
    widths=[3.4, 5.2, 8.0],
)

h2('3.2  非功能需求')
bullet('安全性：密码使用 password_hash 存储；所有写操作校验 CSRF Token；输出统一转义；接口按角色鉴权。')
bullet('可用性：星火调用失败自动降级到本地引擎，页面不中断；数据库支持 SQLite 与 MySQL 一键切换。')
bullet('可维护性：单入口路由 + 分层结构，配置与代码分离，知识库、引导流程均为独立数据源。')
bullet('可演示性：内置演示账号与 7 天模拟数据，打开即可看到完整的统计效果。')

h1('4  系统设计')

h2('4.1  总体架构')
p('系统采用“单入口 + 分层”的结构。public/index.php 负责路由与控制器逻辑，app/ 目录按职责拆分为数据层（Database）、认证层（Auth）、服务层（ChatService、StatsService、KnowledgeBase）、日志层（Logger）、模型提供方（SparkClient / MockProvider）与视图层（Views）。')
p('一次聊天请求的处理链路为：浏览器 fetch 提交 JSON → 路由鉴权与 CSRF 校验 → ChatService 组装上下文 → 知识库检索 → 调用星火或本地引擎 → 消息持久化 → 返回 JSON → 前端渲染气泡、依据与操作按钮。')

h2('4.2  数据库设计')
p('系统共 7 张表，覆盖账号、会话、消息、评价、工单、日志与知识库。核心表结构如下：')
table(
    ['表名', '主要字段', '说明'],
    [
        ['users', 'id, username, password_hash, display_name, role, status', '账号与角色'],
        ['conversations', 'id, user_id, mode, title, created_at, updated_at', '一次咨询会话'],
        ['messages', 'id, conversation_id, role, content, meta', '消息，meta 存来源/建议/情绪'],
        ['feedback', 'message_id, user_id, rating, comment', '满意度评价'],
        ['tickets', 'id, user_id, title, priority, status, assignee_id', '转人工工单'],
        ['logs', 'user_id, action, level, detail, ip, created_at', '操作日志'],
        ['kb_articles', 'category, title, keywords, content', '校园知识库'],
    ],
    widths=[3.2, 9.0, 4.4],
)
p('消息表的 meta 字段以 JSON 保存本次回答的结构化信息（知识库来源、推荐追问、情绪、是否需要转人工），既避免过度拆表，又让前端可以精确渲染“依据 / 建议 / 转人工”区域。')

h2('4.3  权限与安全设计')
p('权限采用角色矩阵控制，页面访问与 API 调用分别校验，避免只在前端隐藏菜单：')
table(
    ['功能', '普通用户', '客服专员', '管理员'],
    [
        ['智能客服 / 历史 / 个人中心', '√', '√', '√'],
        ['提交工单 / 评价', '√', '√', '√'],
        ['数据看板 / 工单管理', '—', '√', '√'],
        ['用户管理 / 日志管理 / 导出日志', '—', '—', '√'],
    ],
    widths=[7.0, 3.6, 3.4, 3.0],
)
p('安全措施包括：密码哈希、Session 固定攻击防护（登录后 session_regenerate_id）、CSRF Token、PDO 预处理防注入、输出 HTML 转义防 XSS、登录失败 5 次锁定 60 秒、敏感信息脱敏后落日志。')

h2('4.4  智能客服流程设计')
p('三种咨询方式共用一套会话与持久化逻辑，差异体现在上下文与生成策略：')
bullet('单轮问答：每次请求新建会话，不携带历史消息，适合快速查询。')
bullet('多轮对话：携带本会话最近 12 条消息；追问未命中知识库时，自动用上一轮用户问题补足检索语境。')
bullet('信息选择：用户先选类别，再选具体问题，系统直接返回结构化的“原因—步骤—入口—贴士”，不依赖模型生成，稳定可控。')
p('真实调用时，系统把知识库 Top3 条目写入 system prompt，并约束模型“优先依据资料、不得编造金额与时间、资料不足建议转人工”。星火返回后按帧拼接 content，直至 header.status=2。')

h2('4.5  创新功能设计')
p('在基本要求之外，系统实现了四项创新：')
bullet('知识库检索增强（RAG-lite）：对关键词、标题二元组、类别进行加权打分，把检索结果作为回答依据并在界面展示来源，降低模型幻觉。')
bullet('情绪识别与预警：识别“投诉、崩溃、气死”等负面表达，回答中先安抚，并自动建议转人工，后台看板可统计。')
bullet('一键工单闭环：对话中可直接生成带上下文摘要的工单，进入客服后台按状态流转，用户端可追踪进度。')
bullet('咨询方式多样化与全链路留痕：同一入口覆盖单轮、多轮、选项式引导，所有操作入库并落文件，支持正则检索与 CSV 导出。')

h1('5  实验步骤')
p('实验按照“环境搭建 → 数据层 → 认证权限 → 客服会话 → 日志导出 → 可视化 → 联调验收”的顺序推进。')

h2('5.1  环境搭建')
p('使用 XAMPP 提供的 PHP 8.0.30，确认 pdo_mysql、pdo_sqlite、mbstring、openssl 扩展已启用。项目采用 PHP 内置服务器启动，避免额外配置虚拟主机：')
code([
    'cd smart-service',
    r'C:\xampp\php\php.exe -S 127.0.0.1:8899 -t public public/index.php',
])

h2('5.2  数据层与自动建表')
p('Database 类封装 PDO 连接、预处理查询与建表逻辑，根据 driver 自动选择 SQLite 或 MySQL 方言；首次访问自动建表并写入演示数据（3 个账号、13 条知识库、约 40 条历史会话、3 个工单）。')

h2('5.3  认证、权限与用户管理')
p('注册时用正则校验用户名（字母开头、4-20 位）与邮箱格式；登录成功后写入 Session 并记录日志；Auth::requireRole 在渲染页面前拦截越权访问。管理员可在用户管理页修改角色、状态与密码。')

h2('5.4  客服会话与星火接入')
p('ChatService 负责会话创建、历史加载、知识库检索、Prompt 组装、模型调用与消息落库。星火客户端自行完成 HMAC-SHA256 鉴权、WebSocket 握手与帧编解码，核心代码如下：')
code([
    '$origin = "host: {$host}\\ndate: {$date}\\nGET {$path} HTTP/1.1";',
    '$signature = base64_encode(hash_hmac("sha256", $origin, $apiSecret, true));',
    '$authorization = base64_encode("api_key=\\"{$apiKey}\\", algorithm=\\"hmac-sha256\\",',
    '    headers=\\"host date request-line\\", signature=\\"{$signature}\\"");',
    '$url = "wss://{$host}{$path}?authorization={$authorization}&date={$date}&host={$host}";',
])

h2('5.5  日志、文件操作与导出')
p('Logger 统一记录动作、级别、详情与 IP，先脱敏手机号、邮箱、身份证号，再同时写入 logs 表与 storage/logs/日期.log 文件（LOCK_EX 防止并发写乱）。导出功能使用 fputcsv 生成带 BOM 的 CSV，浏览器下载后可被 Excel 正确识别。')

h2('5.6  统计可视化')
p('看板数据由 StatsService 聚合，前端使用原生 Canvas 绘制折线图、环形图、柱状图与横向条形图，不依赖 CDN，断网环境同样可以展示。')

h1('6  实验结果')
p('以下截图均来自本机实际运行（管理员 / 普通用户两种视角）。')

h2('6.1  登录与工作台')
img('01-login', '图 1  登录页：左侧品牌区、右侧登录表单与演示账号', 15.2)
img('02-chat', '图 2  智能客服工作台：三种咨询方式与知识库覆盖', 15.2)

h2('6.2  单轮问答与多轮对话')
img('03-chat-answer', '图 3  单轮问答：命中知识库并展示回答依据', 15.2)
p('多轮对话在单轮基础上携带上下文。实测先问“校园网一直认证失败”，再追问“那还是不行怎么办”，系统回答以“接着刚才的问题继续看”开头，并继续给出排查步骤，说明上下文生效。')

h2('6.3  信息选择')
img('04-guided', '图 4  信息选择：按类别进入', 14.4)
img('05-guided-answer', '图 5  选择具体问题后返回推荐处理步骤与办理入口', 15.2)

h2('6.4  历史记录与工单')
img('06-history', '图 6  咨询历史：方式、关键词/正则、日期筛选与分页', 15.2)
img('06b-tickets', '图 7  我的工单：优先级与处理状态', 15.2)

h2('6.5  后台管理')
img('08-users', '图 8  用户管理：角色、状态与账号操作', 15.2)
img('09-logs', '图 9  日志管理：级别统计、正则筛选与文件日志', 15.2)
img('10-tickets', '图 10  工单管理：按状态流转', 15.2)

h2('6.6  数据看板')
img('07-stats', '图 11  数据看板：趋势、方式占比、满意度、工单与热门主题', 13.6)

h1('7  关键问题与解决')
h3('问题 1：星火 WebSocket 握手失败')
p('最初直接使用 wss 地址连接，服务端返回 401。排查后确认鉴权串必须严格按“host、date、request-line”三行拼接，且 date 需为 GMT 格式、参与签名后又作为查询参数原样传递。修正后握手返回 101。')
h3('问题 2：多轮追问答非所问')
p('用户输入“那还是不行”时，单句检索不到知识库。解决方式是在多轮模式下，若当前句未命中，则拼接上一轮用户问题再次检索，并在回答前加承接语，使上下文连续。')
h3('问题 3：中文写入数据库出现乱码')
p('接口测试时发现消息变成问号。定位为测试工具未按 UTF-8 发送 JSON，而非服务端问题；在请求头显式声明 charset=utf-8 并使用 UTF-8 编码后恢复正常，同时在 PDO 连接与页面响应中统一使用 utf8mb4。')
h3('问题 4：普通用户可直接访问后台接口')
p('早期只在前端隐藏菜单，用户手动输入 URL 仍可进入。加入 Auth::requireRole 后，页面与 API 双重校验；越权访问返回 403 并写入 warning 日志，便于审计。')

h1('8  总结与心得')
p('本次综合设计把课堂上分散的知识点串成了一个完整系统：数据库设计决定了会话、消息、工单能否闭环；文件与日志让系统“可追溯”；正则既用于输入校验，也用于日志检索与隐私脱敏；重定向与 Session 则支撑了登录态与操作后的页面流转。')
p('在智能客服部分，我体会最深的是“模型不是万能的”。直接把问题丢给大模型，回答可能流畅但缺少依据；把知识库检索结果作为上下文，并明确要求“资料不足就建议转人工”，回答的可靠性明显提升。同时，工程上必须为外部服务准备好降级方案——星火不可用时自动切换本地引擎，保证演示与答辩不中断。')
p('系统仍有可改进之处：知识库目前是关键词检索，后续可以引入向量检索；工单还缺少消息通知；统计维度可以继续细化到院系与时段。这些都将作为后续学习的方向。')

h1('附录 A  关键代码')
p('（1）星火鉴权 URL 生成', indent=False, bold=True)
code([
    'private function buildAuthUrl(): string {',
    '    $host = parse_url($this->cfg["url"], PHP_URL_HOST);',
    '    $path = parse_url($this->cfg["url"], PHP_URL_PATH);',
    '    $date = gmdate("D, d M Y H:i:s") . " GMT";',
    '    $origin = "host: {$host}\\ndate: {$date}\\nGET {$path} HTTP/1.1";',
    '    $sign = base64_encode(hash_hmac("sha256", $origin, $this->cfg["api_secret"], true));',
    '    $auth = base64_encode("api_key=\\"" . $this->cfg["api_key"] . "\\", algorithm=\\"hmac-sha256\\",',
    '        headers=\\"host date request-line\\", signature=\\"{$sign}\\"");',
    '    return "wss://{$host}{$path}?" . http_build_query([',
    '        "authorization" => $auth, "date" => $date, "host" => $host,',
    '    ]);',
    '}',
])
p('（2）敏感信息脱敏（正则）', indent=False, bold=True)
code([
    '$text = preg_replace("/(?<!\\d)(1[3-9]\\d)\\d{4}(\\d{4})(?!\\d)/", "$1****$2", $text);',
    '$text = preg_replace("/([\\w.+-]{2})[\\w.+-]*@([\\w-]+\\.[\\w.-]+)/u", "$1***@$2", $text);',
    '$text = preg_replace("/(?<!\\d)(\\d{6})\\d{8}(\\d{3}[\\dXx])(?!\\d)/", "$1********$2", $text);',
])
p('（3）CSV 导出（文件操作）', indent=False, bold=True)
code([
    '$fh = fopen($file, "w");',
    'fwrite($fh, "\\xEF\\xBB\\xBF");',
    'fputcsv($fh, ["ID", "时间", "用户", "级别", "动作", "详情", "IP"]);',
    'foreach ($rows as $row) { fputcsv($fh, $row); }',
    'fclose($fh);',
    'header("Content-Type: text/csv; charset=utf-8");',
    'header("Content-Disposition: attachment; filename=\\"" . $filename . "\\"");',
    'readfile($file);',
])
p('（4）统一鉴权', indent=False, bold=True)
code([
    'public static function requireRole(array $roles): void {',
    '    self::requireLogin();',
    '    if (!in_array(self::user()["role"] ?? "user", $roles, true)) {',
    '        Logger::log("permission.denied", $_SERVER["REQUEST_URI"] ?? "", "warning");',
    '        abort(403);',
    '    }',
    '}',
])

h1('附录 B  运行说明')
bullet('启动：双击 start.bat，或执行 php -S 127.0.0.1:8899 -t public public/index.php；')
bullet('演示账号：admin / admin123（管理员）、teacher / teacher123（客服）、student / student123（用户）；')
bullet('切换星火：复制 app/config.local.example.php 为 config.local.php，填写 app_id、api_key、api_secret；')
bullet('切换 MySQL：在 config.local.php 中将 db.driver 改为 mysql 并填写连接信息；')
bullet('重置数据：删除 storage/app.db（及 -wal、-shm 文件）后刷新页面。')

doc.save(OUT)
print('saved', OUT)
