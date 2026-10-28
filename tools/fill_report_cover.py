# -*- coding: utf-8 -*-
"""Fill / update the cover of 《实验报告-智能客服系统》.

用法（PowerShell，Python 用 DSH 运行时自带的解释器）::

    & $py tools\\fill_report_cover.py --name 张三 --sid 20230001 ^
        --class 计算机2301 --place 实验楼A305 --term "2026 年秋季" --date 2026-10-27 --teacher 罗凯伦

规则：
- 只改本脚本认识的封面字段，未传的参数一律保持原样（模板里仍是 *** 就保持 ***）；
- 值写进原单元格/原段落里“承载旧值”的 run，保留该 run 的字体、字号、加粗、对齐；
- 单元格里如果还是 *** 占位符，替换时沿用该占位 run 的格式（下划线等）并去掉多余占位；
- 同时恢复封面表格的原始列宽 3.2 / 6.8 / 3.2 / 3.6 cm（Word→WPS 另存后会被压成 4/4/4/4，
  导致“智能客服 Web 系统的设计与实现”换行、封面排版变形）；可用 --keep-layout 关闭；
- 默认原地覆盖 --in；用 --out 写副本时不影响源文件；
- 保存后重新打开输出文件做自检并打印封面字段表。

字段映射（表 0，0 基）：
    姓名 R1C1 | 学号 R1C3 | 班级 R2C1 | 实验地点 R2C3 | 实验日期 R3C1 | 指导老师 R3C3
    学期  → 封面第 5 段 "课程名称：PHP 程序设计    学期：2026 年秋季"
    指导老师同时同步 评阅老师：X 那一行（两处本来就该一致）
"""
import argparse
import os
import re
import shutil
import sys

from docx import Document
from docx.oxml.ns import qn

DEFAULT_IN = os.path.join(
    os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
    "实验报告-智能客服系统.docx",
)

# (row, col) in cover table
FIELD_CELLS = {
    "name": (1, 1),
    "sid": (1, 3),
    "class": (2, 1),
    "place": (2, 3),
    "date": (3, 1),
    "teacher": (3, 3),
}
FIELD_LABELS = {
    "name": "姓名",
    "sid": "学号",
    "class": "班级",
    "place": "实验地点",
    "date": "实验日期",
    "teacher": "指导老师",
}
LAYOUT_CM = (3.2, 6.8, 3.2, 3.6)
LAYOUT_FLAG = "--keep-layout"


def _set_run_text_preserve(paragraph, new_text):
    """Replace paragraph text using the first run as the format carrier."""
    runs = paragraph.runs
    if not runs:
        paragraph.add_run(new_text)
        return
    carrier = runs[0]
    # If the paragraph is a single run, just rewrite it.
    for run in runs[1:]:
        run._r.getparent().remove(run._r)
    carrier.text = new_text


def _replace_run_text(paragraph, old_text, new_text):
    """Prefer replacing inside the run that actually holds old_text."""
    for run in paragraph.runs:
        if old_text in run.text:
            run.text = run.text.replace(old_text, new_text, 1)
            return True
    return False


def set_cell_value(cell, value):
    """Write value into a cover cell, preserving the value run's formatting."""
    paragraphs = cell.paragraphs
    if not paragraphs:
        cell.text = value
        return
    para = paragraphs[0]
    runs = para.runs
    if not runs:
        para.add_run(value)
        return
    # 优先复用含 *** 的 run（保留占位符格式），否则用第一个 run
    carrier = runs[0]
    for run in runs:
        if "***" in run.text:
            carrier = run
            break
    carrier.text = value
    # 清掉同一单元格里其它 run 的旧内容（保留 run 的格式对象）
    for run in runs:
        if run is not carrier:
            run.text = ""
    # 单元格理论上一段，多余段落移除，避免残留 ***
    for extra in paragraphs[1:]:
        if extra.text.strip():
            _set_run_text_preserve(extra, "")
        extra._p.getparent().remove(extra._p)


def set_paragraph_value(doc, pattern, value, field_label, must=True):
    """Replace the tail matched by pattern in the first matching paragraph."""
    for para in doc.paragraphs:
        if re.search(pattern, para.text):
            old = para.text
            new = re.sub(pattern, value, old, count=1)
            if new == old:
                return False
            _set_run_text_preserve(para, new)
            return True
    if must:
        raise SystemExit(f"未找到封面字段：{field_label}（pattern={pattern!r}）")
    return False


def get_cover_values(doc):
    """Return the current cover field values (for verification / diffing)."""
    table = doc.tables[0]
    vals = {}
    for key, (r, c) in FIELD_CELLS.items():
        vals[key] = table.cell(r, c).text.strip()
    term = ""
    for para in doc.paragraphs:
        m = re.search(r"学期[：:]\s*(.*)$", para.text)
        if m:
            term = m.group(1).strip()
            break
    vals["term"] = term
    teacher_line = ""
    for para in doc.paragraphs:
        m = re.search(r"评阅老师[：:]\s*([^\s_]+)", para.text)
        if m:
            teacher_line = m.group(1)
            break
    vals["teacher_review_line"] = teacher_line
    return vals


def restore_layout(doc):
    """Restore the cover table's intended column widths (cm); return True if changed."""
    table = doc.tables[0]
    grid = table._tbl.find(qn("w:tblGrid"))
    if grid is None:
        return False
    cols = grid.findall(qn("w:gridCol"))
    if len(cols) != len(LAYOUT_CM):
        return False
    widths = [int(round(cm * 567)) for cm in LAYOUT_CM]
    changed = False
    for gc, width in zip(cols, widths):
        if gc.get(qn("w:w")) != str(width):
            gc.set(qn("w:w"), str(width))
            changed = True
    for row in table.rows:
        for cell, width in zip(row.cells, widths):
            tcPr = cell._tc.get_or_add_tcPr()
            tcW = tcPr.find(qn("w:tcW"))
            if tcW is None:
                tcW = tcPr.makeelement(qn("w:tcW"), {})
                tcPr.append(tcW)
            if tcW.get(qn("w:w")) != str(width) or tcW.get(qn("w:type")) != "dxa":
                tcW.set(qn("w:w"), str(width))
                tcW.set(qn("w:type"), "dxa")
                changed = True
    return changed


def apply(doc, args):
    changed = []
    for key, (r, c) in FIELD_CELLS.items():
        value = getattr(args, "value_" + key)
        if value is None:
            continue
        old = doc.tables[0].cell(r, c).text.strip()
        if old == value:
            continue
        set_cell_value(doc.tables[0].cell(r, c), value)
        changed.append(f"{FIELD_LABELS[key]}: {old!r} -> {value!r}")

    if args.term is not None:
        old = get_cover_values(doc)["term"]
        if old != args.term:
            set_paragraph_value(doc, r"(学期[：:]\s*).*$", r"\g<1>" + args.term, "学期")
            changed.append(f"学期: {old!r} -> {args.term!r}")

    if args.value_teacher is not None:
        old_line = get_cover_values(doc)["teacher_review_line"]
        if old_line != args.value_teacher:
            set_paragraph_value(
                doc,
                r"(评阅老师[：:]\s*)[^\s_]+",
                r"\g<1>" + args.value_teacher,
                "评阅老师",
            )
            changed.append(f"评阅老师: {old_line!r} -> {args.value_teacher!r}")

    if not args.keep_layout:
        if restore_layout(doc):
            changed.append("恢复封面表格列宽 3.2/6.8/3.2/3.6 cm")
    return changed


def verify(path, expect):
    doc = Document(path)
    vals = get_cover_values(doc)
    problems = []
    for key, value in expect.items():
        if value is None:
            continue
        if vals.get(key) != value:
            problems.append(f"{FIELD_LABELS.get(key, key)} 期望 {value!r} 实际 {vals.get(key)!r}")
    # cover-only *** check: 封面表格 + 前 16 段
    cover_text = "".join(cell.text for row in doc.tables[0].rows for cell in row.cells)
    cover_text += "".join(p.text for p in doc.paragraphs[:16])
    stars = cover_text.count("***")
    return vals, problems, stars


def main(argv=None):
    ap = argparse.ArgumentParser(description="填写实验报告封面（只改传入字段，保留原格式）")
    ap.add_argument("--name", dest="value_name", default=None, help="姓名")
    ap.add_argument("--sid", dest="value_sid", default=None, help="学号")
    ap.add_argument("--class", dest="value_class", default=None, help="班级")
    ap.add_argument("--place", dest="value_place", default=None, help="实验地点")
    ap.add_argument("--date", dest="value_date", default=None, help="实验日期，如 2026-10-27")
    ap.add_argument("--teacher", dest="value_teacher", default=None, help="指导老师（同步评阅老师）")
    ap.add_argument("--term", default=None, help="学期，如 '2026 年秋季'")
    ap.add_argument("--in", dest="inp", default=DEFAULT_IN, help="输入 docx（默认项目内报告）")
    ap.add_argument("--out", dest="out", default=None, help="输出 docx（默认原地覆盖 --in）")
    ap.add_argument(LAYOUT_FLAG, action="store_true", help="不恢复封面表格列宽")
    args = ap.parse_args(argv)

    out = args.out or args.inp
    if not os.path.isfile(args.inp):
        raise SystemExit(f"输入文件不存在：{args.inp}")
    if os.path.abspath(out) != os.path.abspath(args.inp):
        shutil.copyfile(args.inp, out)

    doc = Document(out)
    changed = apply(doc, args)
    if not changed and os.path.abspath(out) == os.path.abspath(args.inp):
        print("没有字段需要修改，且布局无需恢复；未重写文件。")
    else:
        doc.save(out)
        print(f"已写入：{out}")
        for line in changed:
            print("  -", line)

    expect = {
        "name": args.value_name,
        "sid": args.value_sid,
        "class": args.value_class,
        "place": args.value_place,
        "date": args.value_date,
        "teacher": args.value_teacher,
        "term": args.term,
    }
    vals, problems, stars = verify(out, expect)
    print("\n封面字段自检：")
    for key in ("name", "sid", "class", "place", "date", "teacher", "term"):
        print(f"  {FIELD_LABELS.get(key, key)}: {vals.get(key)!r}")
    print(f"  评阅老师：{vals.get('teacher_review_line')!r}")
    print(f"  封面残留 *** 数：{stars}")
    if stars:
        problems.append("封面仍有 *** 占位符")
    if problems:
        print("\n自检问题：")
        for p in problems:
            print("  !", p)
        return 1
    print("\n自检通过：字段映射正确、封面无 *** 残留。")
    return 0


if __name__ == "__main__":
    sys.exit(main())
