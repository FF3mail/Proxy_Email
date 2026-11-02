import importlib.util
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SPEC_CHECKER = ROOT / 'scripts' / 'check_spec_format.py'


def _load_checker():
    spec = importlib.util.spec_from_file_location('check_spec_format', SPEC_CHECKER)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


class CheckSpecFormatTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.checker = _load_checker()

    def test_valid_minimal_sample(self):
        sample = '\n'.join(
            [
                '# SPEC — test',
                '## 0.',
                '### 0.1',
                '### 0.4',
                '## 1.',
                '## 2.',
                '## 3.',
                '## 4.',
                '## 5.',
                '## 6.',
                '## 7.',
                '## 8.',
                '## 9.',
                '## 10.',
                '## 11.',
                '## 12.',
                '### 12.1',
                '**ACC-1** `[обязательный]` One.',
                '**ACC-2** `[желательный]` Two.',
                '## 13.',
                '### 13.1',
                '| ID | Решение |',
                '|---|---|',
                '| D-00 | anchor |',
                '### 13.2',
                '| ID | Требование | Что делает код сейчас |',
                '|---|---|---|',
                '| DEV-1 | X | Y |',
                '### 13.3',
                '| ID | Пункт |',
                '|---|---|',
                '| P-1 | open |',
                '## 14.',
            ]
        )
        errors, summary = self.checker.validate_spec_text(sample, spec_label='sample')
        self.assertEqual(errors, [])
        self.assertEqual(summary['acc_total'], 2)
        self.assertEqual(summary['acc_mandatory'], 1)
        self.assertEqual(summary['acc_optional'], 1)
        self.assertEqual(summary['dev_rows'], 1)

    def test_duplicate_acc(self):
        sample = '\n'.join(
            [
                '# SPEC — test',
                *[f'## {n}.' for n in range(15)],
                '### 0.1',
                '### 0.4',
                '### 12.1',
                '**ACC-1** `[обязательный]` A.',
                '**ACC-1** `[обязательный]` B.',
                '### 13.1',
                '| D-00 | x |',
                '### 13.2',
                '| DEV-1 | a | b |',
                '### 13.3',
                '| P-1 | x |',
            ]
        )
        errors, _ = self.checker.validate_spec_text(sample)
        self.assertTrue(any('duplicate ACC' in e for e in errors))

    def test_missing_acc_label(self):
        sample = '\n'.join(
            [
                '# SPEC — test',
                *[f'## {n}.' for n in range(15)],
                '### 0.1',
                '### 0.4',
                '### 12.1',
                '**ACC-1** without label.',
                '### 13.1',
                '| D-00 | x |',
                '### 13.2',
                '| DEV-1 | a | b |',
                '### 13.3',
                '| P-1 | x |',
            ]
        )
        errors, _ = self.checker.validate_spec_text(sample)
        self.assertTrue(any('обязательный' in e or 'желательный' in e for e in errors))

    def test_missing_section(self):
        sample = '\n'.join(
            [
                '# SPEC — test',
                '## 0.',
                '### 0.1',
                '### 0.4',
            ]
        )
        errors, _ = self.checker.validate_spec_text(sample)
        self.assertTrue(any('missing section heading: ## 14.' in e for e in errors))


if __name__ == '__main__':
    unittest.main()
