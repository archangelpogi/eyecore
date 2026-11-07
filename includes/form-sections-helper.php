<?php
/**
 * Get form sections for an appointment
 * Reads form_sections JSON from services table via appointment_services
 */

if (!function_exists('getAppointmentFormSections')) {
    function getAppointmentFormSections(PDO $pdo, int $appointmentId): array {
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    s.id,
                    s.name,
                    s.category,
                    s.form_sections,
                    aps.price
                FROM appointment_services aps
                JOIN services s ON s.id = aps.service_id
                WHERE aps.appointment_id = ?
                ORDER BY aps.id ASC
            ");
            $stmt->execute([$appointmentId]);
            $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $allSections = [];
            $servicesWithSections = [];
            
            foreach ($services as $svc) {
                // Decode JSON
                $sections = [];
                if (!empty($svc['form_sections'])) {
                    $decoded = json_decode($svc['form_sections'], true);
                    if (is_array($decoded)) {
                        $sections = $decoded;
                    }
                }
                
                // Fallback kung walang naka-set
                if (empty($sections)) {
                    $sections = ['visual_acuity', 'external_exam'];
                }
                
                $svc['form_sections'] = $sections;
                $servicesWithSections[] = $svc;
                
                foreach ($sections as $sec) {
                    $allSections[$sec] = true;
                }
            }
            
            return [
                'required_sections' => array_keys($allSections),
                'services_with_sections' => $servicesWithSections
            ];
            
        } catch (Exception $e) {
            error_log("getAppointmentFormSections error: " . $e->getMessage());
            return [
                'required_sections' => ['visual_acuity', 'external_exam'],
                'services_with_sections' => []
            ];
        }
    }
}

/**
 * Valid list of section keys (for validation)
 */
if (!function_exists('getValidFormSectionKeys')) {
    function getValidFormSectionKeys(): array {
        return [
            'visual_acuity',
            'refraction',
            'external_exam',
            'contact_lens_assessment',
            'glaucoma_screening',
            'retina_screening',
            'cataract_evaluation',
            'treatment_notes',
            'lens_recommendation',
        ];
    }
}