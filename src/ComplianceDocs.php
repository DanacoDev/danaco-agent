<?php

class ComplianceDocs
{
    public const TERMS_VERSION = '1';

    public const INTENDED_PURPOSE = 'Agent Danaco is a self-hosted software framework for language-model-assisted project work (chat, files, tasks, optional tools) on hardware the operator controls. It is designed to act with a high degree of autonomy within the tools the operator enables. It is not intended for prohibited AI practices or for high-risk AI system use cases under Annex III of the EU AI Act (including employment decisions, creditworthiness, biometric categorisation/emotion recognition, and critical access to essential private/public services). The organisation that installs and configures it determines the purpose of each deployment and is responsible for lawful use under the laws that apply to them.';

    public function __construct(private Database $db)
    {
    }

    public function termsAccepted(): bool
    {
        return (string)$this->db->getSetting('terms_accepted_version', '') === self::TERMS_VERSION;
    }

    public function acceptTerms(): void
    {
        $this->db->setSetting('terms_accepted_version', self::TERMS_VERSION);
        $this->db->setSetting('terms_accepted_at', gmdate('Y-m-d H:i:s'));
        $this->db->logCompliance('terms_accepted', 'system', 'Terms and Acceptable Use v' . self::TERMS_VERSION . ' accepted.', [
            'version' => self::TERMS_VERSION,
        ]);
    }

    public function showComplianceBox(): bool
    {
        $value = (string)$this->db->getSetting('ai_act_applies', '');
        return $value === 'yes' || $value === 'unsure';
    }

    public function status(): array
    {
        $applies = (string)$this->db->getSetting('ai_act_applies', '');
        $logging = (string)$this->db->getSetting('compliance_logging', '0') === '1';
        $hitl = (string)$this->db->getSetting('hitl_external_actions', '0') === '1';
        $exported = (string)$this->db->getSetting('system_card_exported_at', '');
        return [
            'terms_version' => self::TERMS_VERSION,
            'terms_accepted' => $this->termsAccepted(),
            'terms_accepted_at' => (string)$this->db->getSetting('terms_accepted_at', ''),
            'ai_act_applies' => $applies,
            'show_compliance_box' => $this->showComplianceBox(),
            'compliance_logging' => $logging,
            'hitl_external_actions' => $hitl,
            'responsible_person' => (string)$this->db->getSetting('compliance_responsible_person', ''),
            'intended_use_notes' => (string)$this->db->getSetting('compliance_intended_use', ''),
            'system_card_exported_at' => $exported,
            'retention_days' => (int)$this->db->getSetting('compliance_retention_days', '90'),
        ];
    }

    public function disclosureSnippet(): string
    {
        return 'This conversation is assisted by Agent Danaco, an AI system. Outputs can be wrong. A human operator remains responsible for decisions that affect people or the business.';
    }

    public function systemCardMarkdown(): string
    {
        $settings = $this->db->allSettings();
        $version = $this->readVersion();
        $status = $this->status();
        $mode = (string)($settings['model_mode'] ?? 'cpu_failsafe');
        $cloudUse = (string)($settings['cloud_api_use'] ?? '');
        $full = ($settings['full_system_control'] ?? '0') === '1' ? 'On' : 'Off';
        $lines = [
            '# Agent Danaco — Instructions for Use / System Card',
            '',
            'Generated: ' . gmdate('Y-m-d H:i:s') . ' UTC',
            'Software version: ' . $version,
            '',
            '## Roles',
            '',
            '- **Framework provider:** Danaco supplies the self-hosted agent framework (software).',
            '- **Operator / deployer:** The organisation that installs and configures this copy chooses purpose, data, models, and tools, and puts the system into service.',
            '- **Cloud model providers:** Separate parties when an external LLM API is enabled.',
            '',
            '## Intended purpose',
            '',
            self::INTENDED_PURPOSE,
            '',
            '## This deployment',
            '',
            '- EU AI Act applicability (operator answer): ' . ($status['ai_act_applies'] !== '' ? $status['ai_act_applies'] : 'not set'),
            '- Responsible person: ' . ($status['responsible_person'] !== '' ? $status['responsible_person'] : '(not set)'),
            '- Operator notes on intended use: ' . ($status['intended_use_notes'] !== '' ? $status['intended_use_notes'] : '(not set)'),
            '',
            '## Models and runtime',
            '',
            '- Model mode: ' . $mode,
            '- Local model: ' . (string)($settings['local_model'] ?? ''),
            '- CPU failsafe model: ' . (string)($settings['cpu_failsafe_model'] ?? ''),
            '- External API use: ' . ($cloudUse !== '' ? $cloudUse : 'not in use'),
            '- External model: ' . (string)($settings['cloud_model'] ?? ''),
            '- External base URL: ' . (string)($settings['cloud_base_url'] ?? ''),
            '- Search: Bing, Google, and DuckDuckGo, with no API key',
            '- Full system control: ' . $full,
            '',
            '## Human oversight features',
            '',
            '- Stop generation in chat',
            '- Install confirmation before dependency installs',
            '- Kanban review column (agent cannot mark work done)',
            '- Optional extra approval gates for email / FTP / MCP: ' . ($status['hitl_external_actions'] ? 'On' : 'Off'),
            '- Optional compliance action log: ' . ($status['compliance_logging'] ? 'On' : 'Off'),
            '- Log retention (days): ' . $status['retention_days'],
            '',
            '## Limitations',
            '',
            '- Language-model outputs can be incorrect, biased, or incomplete.',
            '- Scheduled tasks may run without an interactive operator present.',
            '- Enabling cloud models, search, email, FTP, or MCP sends data to third parties under their terms.',
            '- Optional oversight tools do not certify compliance with the EU AI Act or other law.',
            '',
            '## Transparency notice (optional, for people interacting with the AI)',
            '',
            $this->disclosureSnippet(),
            '',
        ];
        return implode("\n", $lines);
    }

    private function readVersion(): string
    {
        $path = dirname(__DIR__) . '/VERSION';
        if (!is_file($path)) {
            return 'unknown';
        }
        $line = trim((string)file_get_contents($path));
        return $line !== '' ? $line : 'unknown';
    }
}
