#!/usr/bin/env python3
"""Synthetic checker regressions. Never product evidence or P1 execution output."""
import importlib.util
import json
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

SOURCE = Path(__file__).with_name('check-p1.py')
REAL_PLAN = SOURCE.parents[2]


def table(headers, rows):
    return '\n'.join(['| ' + ' | '.join(headers) + ' |', '| ' + ' | '.join(['---'] * len(headers)) + ' |'] +
                     ['| ' + ' | '.join(map(str, row)) + ' |' for row in rows])


def sections(parts):
    return '\n\n'.join('## ' + title + '\n\n' + body for title, body in parts)


class CheckerRegression(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='billium-p1-check-')
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name) / 'repo'
        self.plan = self.repo / 'plans' / REAL_PLAN.name
        checker = self.plan / 'artifacts/P1-design/check-p1.py'
        checker.parent.mkdir(parents=True)
        shutil.copyfile(SOURCE, checker)
        spec = importlib.util.spec_from_file_location('isolated_checker', checker)
        self.c = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.c)
        for name in ('P1-corpus.md', 'P1-audit-inventory.md', 'P1-acceptance-inventory.md', 'P1-sources.md'):
            self.put('research/' + name, (REAL_PLAN / 'research' / name).read_text())
        self.fixture()

    def put(self, path, content, repo=False):
        target = (self.repo if repo else self.plan) / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content + '\n', encoding='utf-8')
        return target

    def change(self, path, old, new, repo=False):
        target = (self.repo if repo else self.plan) / path
        text = target.read_text()
        self.assertIn(old, text)
        target.write_text(text.replace(old, new))
        for cached in (self.c.file_text, self.c.inventory, self.c.known_ids):
            cached.cache_clear()

    def report(self, item):
        result = self.c.Report(item)
        self.c.CHECKS[item](result)
        return result.errors

    def red(self, item, contains):
        errors = self.report(item)
        self.assertTrue(any(contains in error for error in errors), errors)

    def fixture(self):
        reqs = [f'REQ-{area}-01' for area in ('OWN', 'PROF', 'CON', 'FAIL', 'ACC', 'NBR', 'OUT')]
        reqs += [f'INV-{n:02d}' for n in range(1, 9)]
        self.reqs = reqs
        self.put('docs/requirements.md', sections([
            ('Владение: state → writer', table(['ID', 'Требование', 'Источник'],
               [[i, 'Synthetic requirement ' + i, 'SRC-001 §1'] for i in reqs])),
            *[(heading, 'Synthetic section') for heading in ('Профили', 'Контракты', 'Инварианты',
             'Отказы', 'Приёмочные сценарии', 'Требования к соседям', 'Вне границ', 'Конфликты')]
        ]), repo=True)
        self.put('knowledge/corpus-ledger.md', sections([
            ('Леджер', table(['SRC', 'Диспозиция', 'Требования', 'Примечание'],
                [[i, 'без новых требований', '—', 'Synthetic fixture, no source reading claimed']
                 for i in self.c.inventory('P1-corpus.md')])),
            ('Итог прохода', 'Synthetic line 1\nSynthetic line 2\nSynthetic line 3')]))
        audit_ids = self.c.audit_ids()
        self.put('knowledge/audit-matrix.md', sections([
            ('Покрытие аудита', table(['ID', 'Диспозиция', 'Ссылки', 'Примечание'],
                [[i, 'учтено', 'CL-01', 'Synthetic mapping'] for i in audit_ids])),
            ('Утверждения', table(['ID', 'Тема', 'Утверждение', 'Статус', 'Источник', 'Нужна проверка'],
                [['CL-01', 'Synthetic', 'Synthetic claim', 'hypothesis', 'AUD-001', 'DR-01']])),
            ('Сводная матрица', table(['Тема', *self.c.CRITERIA],
                [[i, *(['hypothesis CL-01'] * 8)] for i in self.c.TOPICS])),
            ('Поправки и gaps', table(['ID', 'Суть', 'Как учтено', 'Требования'],
                [[i, 'Synthetic', 'Synthetic disposition', 'REQ-FAIL-01']
                 for i in audit_ids if i.startswith(('AC-', 'G-'))])),
            ('Fault stories → требования', table(['ID', 'Требования'],
                [[i, 'REQ-FAIL-01'] for i in audit_ids if i.startswith('FS-')])),
            ('Кандидаты', 'Synthetic candidate 1\nSynthetic candidate 2\nSynthetic candidate 3')]))
        audit = self.c.inventory('P1-audit-inventory.md')
        for key, name in self.c.DR_FILES.items():
            pending = ['CL-01'] if key == 'DR01' else []
            for ident, row in audit.items():
                if ident.startswith('C-') and 'DR-0' + key[-1] in self.c.cell(row, 2):
                    pending.append(ident)
            self.put('knowledge/research/' + name, sections([
                ('Утверждения', table(['ID', 'Утверждение', 'Источник', 'Дата', 'Версия', 'Статус'],
                    [[f'{key}-{n:02d}', 'Synthetic claim', 'https://example.invalid/spec',
                      '2026-10-14', 'fixture', 'verified-doc'] for n in range(1, 4)])),
                ('Покрытие задания', table(['ID', 'Диспозиция', 'Ссылки'],
                    [[i, 'ответ', key + '-01'] for i in audit if i.startswith(key + '-T')])),
                ('Проверка утверждений аудита', table(['ID', 'Диспозиция', 'Ссылки'],
                    [[i, 'подтверждено', key + '-01'] for i in pending])),
                ('Выводы для Billium', 'Synthetic conclusion CL-01'), ('Открыто', 'Нет')]))
        data = {'schema': 'billium-methods/v1', 'generated': '2026-10-14', 'practices': [
            {'id': f'M-{n:02d}', 'topic': topic, 'practice': 'Synthetic practice',
             'source': {'repo': 'agentools', 'path': 'README.md'}, 'verdict': 'adapt',
             'reason': 'Synthetic reason', 'billium': 'Synthetic adaptation', 'phase': 'P2'}
            for n, topic in enumerate(self.c.METHOD_TOPICS, 1)],
            'sources_reviewed': [{'path': path.rstrip('/'), 'practices': ['M-01'], 'note': ''}
                                 for path in self.c.source_map_paths((6, 7))]}
        self.put('knowledge/methods.json', json.dumps(data, ensure_ascii=False))
        for n in range(9, 19):
            self.put(f'decisions/D{n}-synthetic.md', f'''---
status: accepted
items: [P1.6]
---
**Evidence:** INV-01
## Why
- Альтернатива A synthetic
- Альтернатива B synthetic
''')
        self.put('docs/architecture/target-architecture.md', sections([
            ('Решения по областям', table(['Область', 'Решение', 'Основа', 'D#'],
                [[area, 'build', 'INV-01', 'D' + str(n)] for n, area in enumerate(self.c.AREAS, 9)])),
            ('Профили embedded и server', 'Synthetic'), ('Стек', 'PHP Dart'),
            ('Пакеты', table(['Пакет', 'Профиль', 'Потребитель', 'Инвариант'],
                [['axiomasoft/billium-core', 'embedded/server', 'Synthetic consumer', 'INV-01']])),
            ('Границы с соседями', 'REQ-NBR-01')]), repo=True)
        self.put('docs/architecture/data-model.md', sections([
            ('ERD', '```mermaid\nerDiagram\n```'),
            ('FSM денежной операции', '```mermaid\nstateDiagram-v2\n```'),
            ('FSM инвойса', '```mermaid\nstateDiagram-v2\n```'),
            ('Инварианты', table(['ID', 'Инвариант', 'Constraint', 'Lock', 'Тест-идея', 'Источник'],
                [[i, 'Synthetic invariant', 'Synthetic constraint', 'Synthetic lock', 'Synthetic test', i]
                 for i in reqs if i.startswith('INV-')])),
            ('Коррекции', 'Synthetic'), ('Retention и restore', 'Synthetic'),
            ('Операции и блокировки', 'Synthetic interference matrix'),
            ('Словарь данных', 'Synthetic field dictionary'),
            ('Диспозиции новых требований', 'Synthetic: no incoming proposals'),
            ('Fault stories', table(['ID', 'Механизм', 'Где в модели', 'Основание'],
                [[i, 'Synthetic mechanism', 'Synthetic place', 'INV-01'] for i in audit_ids if i.startswith('FS-')]))
        ]), repo=True)
        self.put('knowledge/target-structure.md', '\n'.join('Synthetic structure ' + str(n) for n in range(20)))
        self.put('docs/contracts/billium-contracts-v1.md', sections([
            ('Принципы', 'Synthetic'),
            ('Оплаты и возвраты', 'payment.execute.v1 payment.outcome.v1\n' + table(
                ['Поле пилота', 'Поле Billium', 'Совместимость'], [['operation_id', 'operation_id', 'compatible']])),
            ('Usage и overage', 'Synthetic'), ('Obligations и invoices', 'Synthetic'),
            ('События и receipts', 'Synthetic'),
            ('Ошибки', table(['Код', 'Когда', 'Повторяемо', 'Действие клиента', 'Основание'],
                [['synthetic', 'Synthetic', 'no', 'Synthetic', 'INV-01']])),
            ('План fixtures', table(['Набор', 'Что проверяет', 'PHP', 'Dart', 'Оракул'],
                [['Synthetic', 'Synthetic', 'shared', 'shared', 'Synthetic oracle']])),
            ('Трассировка', table(['Элемент', 'Основание'], [[f'Synthetic-{n}', 'INV-01'] for n in range(5)]))
        ]), repo=True)
        ids = list(self.c.inventory('P1-acceptance-inventory.md')) + ['ACC-CON-01', 'ACC-RST-01']
        self.put('docs/acceptance-catalog.md', sections([
            ('Пилот PB', table(['ID', 'Сценарий', 'Сторона', 'Фаза', 'Требования', 'Контракт', 'Примечание'],
                [[i, 'Synthetic scenario', 'billium', 'P2', 'INV-01', 'payment.execute.v1', 'Synthetic'] for i in ids])),
            *[(title, 'Synthetic section') for title in ('Prerequisites PF', 'MG, FD, SB, EC',
                                                       'Concurrency (N процессов)', 'Restore, clone, lost response')],
            ('Покрытие требований', table(['ID', 'Диспозиция', 'Сценарии', 'Основание'],
                [[i, 'учтено', 'PB-01', 'D9'] for i in reqs]))]), repo=True)
        self.put('knowledge/roadmap-proposal.md', sections([
            ('P' + str(n), '**Цель:** synthetic\n**Выходы:** synthetic\n**Зависит от:** P1\n**Решения:** D9\n**Приёмка:** PB-01\n**Вопросы/риски:** нет')
            for n in range(2, 11)]))
        self.put('knowledge/owner-questions.md', table(['ID', 'Вопрос', 'Варианты', 'Рекомендация', 'Блокирует'],
            [[i, 'Synthetic PSP валюты Memster', 'Synthetic', 'D9', 'P2']
             for i in ('I-01', 'I-02', 'I-16', 'I-28', 'BRAIN-REPO')]))
        self.put('knowledge/brain-updates.md', sections([
            (title, 'Brain commit: не применено — synthetic test fixture')
            for title in ('Package-Status', 'Bridge-Catalog', 'CD-предложения')]))
        for path in ('.ai/guidelines/agent-entrypoint.md', 'README.md'):
            self.put(path, 'Dart client contracts', repo=True)
        self.put('reviews/P1-review.md', '**Verdict:** GREEN\n' + sections([
            ('Проверено', 'Synthetic check'), ('Findings', table(['ID', 'Severity', 'Где', 'Суть', 'Owning item'], []))]))

    def test_complete_synthetic_artifacts_pass_all_checks(self):
        for item in self.c.CHECKS:
            with self.subTest(item=item):
                self.assertEqual([], self.report(item))

    def test_missing_each_original_scenario_is_rejected(self):
        path = self.repo / 'docs/acceptance-catalog.md'
        original = path.read_text()
        for ident in self.c.inventory('P1-acceptance-inventory.md'):
            with self.subTest(ident=ident):
                path.write_text(re.sub(r'^\| ' + ident + r' \|.*\n', '', original, flags=re.M))
                self.c.file_text.cache_clear(); self.c.known_ids.cache_clear()
                self.red('P1.7', 'нет ' + ident)
        path.write_text(original)

    def test_red_verdict_fails_closure(self):
        self.change('reviews/P1-review.md', 'Verdict:** GREEN', 'Verdict:** RED')
        self.red('P1.9', 'verdict RED')

    def test_major_finding_cannot_be_green(self):
        path = self.plan / 'reviews/P1-review.md'
        path.write_text(path.read_text() + '| F-01 | major | model | bad | P1.6 |\n\n### F-01 — bad\n')
        self.red('P1.9', 'GREEN при наличии')

    def test_pre_review_does_not_require_verdict(self):
        (self.plan / 'reviews/P1-review.md').unlink()
        path = self.plan / 'artifacts/P1-design/check-p1.py'
        result = subprocess.run([sys.executable, str(path), 'pre-review'], capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        result = subprocess.run([sys.executable, str(path), 'all'], capture_output=True, text=True)
        self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        self.assertIn('P1.9: RED', result.stdout)

    def test_unresolved_requirement_source_is_rejected(self):
        self.change('docs/requirements.md', 'SRC-001 §1', 'SRC-999 §1', repo=True)
        self.red('P1.1', 'SRC-999')

    def test_empty_requirement_is_rejected(self):
        self.change('docs/requirements.md', 'Synthetic requirement INV-01', '—', repo=True)
        self.red('P1.1', 'INV-01 без содержания')

    def test_used_ledger_requires_reverse_source_link(self):
        self.change('knowledge/corpus-ledger.md', '| SRC-002 | без новых требований | — |', '| SRC-002 | использован | INV-01 |')
        self.red('P1.1', 'ни одно указанное требование')

    def test_requirement_cannot_lack_acceptance_disposition(self):
        self.change('docs/acceptance-catalog.md', '| INV-02 | учтено | PB-01 | D9 |', '', repo=True)
        self.red('P1.7', 'покрытие REQ/INV неполно')

    def test_disposition_requires_existing_scenario(self):
        self.change('docs/acceptance-catalog.md', '| INV-02 | учтено | PB-01 |', '| INV-02 | учтено | MG-99 |', repo=True)
        self.red('P1.7', 'без разрешимых сценариев')

    def test_neighbor_disposition_requires_reason_and_boundary(self):
        self.change('docs/acceptance-catalog.md', '| MG-01 | Synthetic scenario | billium |', '| MG-01 | Synthetic scenario | сосед:Tariffler |', repo=True)
        self.red('P1.7', 'вне Billium без причины')

    def test_duplicate_claim_fails(self):
        row='| CL-01 | Synthetic | Synthetic claim | hypothesis | AUD-001 | DR-01 |'
        self.change('knowledge/audit-matrix.md', row, row + '\n' + row)
        self.red('P1.3', 'повтор утверждения')

    def test_matrix_na_still_requires_provenance(self):
        self.change('knowledge/audit-matrix.md', 'hypothesis CL-01', 'n/a')
        self.red('P1.3', 'нет основания')

    def test_mention_does_not_close_research(self):
        self.change('knowledge/research/DR-01.md', '| CL-01 | подтверждено | DR01-01 |', 'CL-01 is merely mentioned here')
        self.red('P1.4', 'нет диспозиции CL-01')

    def test_research_reference_must_resolve(self):
        self.change('knowledge/research/DR-01.md', '| CL-01 | подтверждено | DR01-01 |', '| CL-01 | подтверждено | DR01-99 |')
        self.red('P1.4', 'DR01-99')

    def test_verified_claim_requires_url(self):
        self.change('knowledge/research/DR-01.md', 'https://example.invalid/spec', 'unverified guess')
        self.red('P1.4', 'verified без URL')

    def test_missing_method_topic_fails(self):
        self.change('knowledge/methods.json', '"jcs-oracle"', '"spec-revisions"')
        self.red('P1.5', 'jcs-oracle')

    def test_methods_non_object_returns_red(self):
        self.put('knowledge/methods.json', '[]')
        self.red('P1.5', 'корень JSON не объект')

    def test_missing_stack_layout_profile_decisions_fails(self):
        for n in range(16,19):
            (self.plan / f'decisions/D{n}-synthetic.md').unlink()
        self.red('P1.6', '< 10')

    def test_missing_invariant_fails(self):
        self.change('docs/architecture/data-model.md', '| INV-08 | Synthetic invariant | Synthetic constraint | Synthetic lock | Synthetic test | INV-08 |', '', repo=True)
        self.red('P1.6', 'нет INV-08')

    def test_missing_interference_model_fails(self):
        self.change('docs/architecture/data-model.md', '## Операции и блокировки', '## Omitted', repo=True)
        self.red('P1.6', 'Операции и блокировки')

    def test_roadmap_requires_acceptance(self):
        self.change('knowledge/roadmap-proposal.md', '**Приёмка:**', '**Omitted:**')
        self.red('P1.8', 'Приёмка')

    def test_no_cd_proposals_needs_no_fake_commit(self):
        self.change('knowledge/brain-updates.md',
                    '## CD-предложения\n\nBrain commit: не применено — synthetic test fixture',
                    '## CD-предложения\n\nBrain commit: не требуется — нет предложений изменения канона')
        self.assertEqual([], self.report('P1.8'))


if __name__ == '__main__':
    unittest.main(verbosity=2)
