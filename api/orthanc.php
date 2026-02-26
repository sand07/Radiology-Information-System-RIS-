<?php
/**
 * Orthanc PACS API Helper
 * Menangani koneksi dan komunikasi dengan Orthanc REST API
 */

class OrthancAPI {
    private $baseUrl;
    private $username;
    private $password;
    private $lastError;

    public function __construct($baseUrl = '', $username = '', $password = '') {
        $this->baseUrl = $baseUrl ?: ORTHANC_URL;
        $this->username = $username ?: ORTHANC_USERNAME;
        $this->password = $password ?: ORTHANC_PASSWORD;
    }

    /**
     * Membuat request ke Orthanc API
     * @param string $endpoint
     * @param string $method
     * @param array $data
     * @return array
     */
    private function request($endpoint, $method = 'GET', $data = null) {
        try {
            $url = $this->baseUrl . $endpoint;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

            // Set timeout
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            // Tambah authentication jika ada
            if (!empty($this->username) && !empty($this->password)) {
                curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
            }

            // Set data jika ada
            if ($data !== null) {
                if (is_array($data)) {
                    $data = json_encode($data);
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            curl_close($ch);

            if ($error) {
                $this->lastError = $error;
                return ['success' => false, 'error' => $error];
            }

            if ($httpCode !== 200 && $httpCode !== 201) {
                $this->lastError = "HTTP Error: $httpCode - $response";
                return ['success' => false, 'error' => $this->lastError, 'status' => $httpCode];
            }

            $result = json_decode($response, true);
            return ['success' => true, 'data' => $result];

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get daftar patients
     * @param int $limit
     * @return array
     */
    public function getPatients($limit = 100) {
        $result = $this->request('/patients');

        if ($result['success']) {
            $patients = [];
            $patientIds = array_slice($result['data'], 0, $limit);

            foreach ($patientIds as $patientId) {
                $patientDetails = $this->getPatientDetails($patientId);
                if ($patientDetails['success']) {
                    $patients[] = $patientDetails['data'];
                }
            }

            return ['success' => true, 'data' => $patients];
        }

        return $result;
    }

    /**
     * Get detail patient
     * @param string $patientId
     * @return array
     */
    public function getPatientDetails($patientId) {
        $result = $this->request("/patients/$patientId");

        if ($result['success']) {
            return [
                'success' => true,
                'data' => [
                    'id' => $patientId,
                    'details' => $result['data']['PatientMainDicomTags'] ?? [],
                    'studies' => $result['data']['Studies'] ?? []
                ]
            ];
        }

        return $result;
    }

    /**
     * Get daftar studies untuk seorang patient
     * @param string $patientId
     * @return array
     */
    public function getStudies($patientId) {
        $result = $this->request("/patients/$patientId");

        if ($result['success']) {
            $studies = [];
            $studyIds = $result['data']['Studies'] ?? [];

            foreach ($studyIds as $studyId) {
                $studyDetails = $this->getStudyDetails($studyId);
                if ($studyDetails['success']) {
                    $studies[] = $studyDetails['data'];
                }
            }

            return ['success' => true, 'data' => $studies];
        }

        return $result;
    }

    /**
     * Get detail study
     * @param string $studyId
     * @return array
     */
    public function getStudyDetails($studyId) {
        $result = $this->request("/studies/$studyId");

        if ($result['success']) {
            return [
                'success' => true,
                'data' => [
                    'id' => $studyId,
                    'details' => $result['data']['MainDicomTags'] ?? [],
                    'series' => $result['data']['Series'] ?? []
                ]
            ];
        }

        return $result;
    }

    /**
     * Get daftar series dalam study
     * @param string $studyId
     * @return array
     */
    public function getSeries($studyId) {
        $result = $this->getStudyDetails($studyId);

        if ($result['success']) {
            $seriesList = [];
            $seriesIds = $result['data']['series'] ?? [];

            foreach ($seriesIds as $seriesId) {
                $seriesDetails = $this->getSeriesDetails($seriesId);
                if ($seriesDetails['success']) {
                    $seriesList[] = $seriesDetails['data'];
                }
            }

            return ['success' => true, 'data' => $seriesList];
        }

        return $result;
    }

    /**
     * Get detail series
     * @param string $seriesId
     * @return array
     */
    public function getSeriesDetails($seriesId) {
        $result = $this->request("/series/$seriesId");

        if ($result['success']) {
            return [
                'success' => true,
                'data' => [
                    'id' => $seriesId,
                    'details' => $result['data']['MainDicomTags'] ?? [],
                    'instances' => $result['data']['Instances'] ?? []
                ]
            ];
        }

        return $result;
    }

    /**
     * Get gambar DICOM dalam format JPEG/PNG
     * @param string $instanceId
     * @param string $frame
     * @return string (binary image data)
     */
    public function getDicomImage($instanceId, $frame = 0) {
        try {
            $url = $this->baseUrl . "/instances/$instanceId/preview";

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            if (!empty($this->username) && !empty($this->password)) {
                curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            curl_close($ch);

            if ($error || $httpCode !== 200) {
                return null;
            }

            return $response;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get instance details
     * @param string $instanceId
     * @return array
     */
    public function getInstanceDetails($instanceId) {
        $result = $this->request("/instances/$instanceId");

        if ($result['success']) {
            return [
                'success' => true,
                'data' => [
                    'id' => $instanceId,
                    'details' => $result['data']['MainDicomTags'] ?? [],
                    'tags' => $result['data']['DicomWeb'] ?? []
                ]
            ];
        }

        return $result;
    }

    /**
     * Get total studies di Orthanc
     * @return int
     */
    public function getTotalStudies() {
        $result = $this->request('/studies');

        if ($result['success']) {
            return count($result['data'] ?? []);
        }

        return 0;
    }

    /**
     * Get total worklist di Orthanc
     * @return int
     */
    public function getTotalWorklist() {
        return $this->getTotalStudies();
    }

    /**
     * Search studies berdasarkan kriteria
     * @param array $criteria
     * @return array
     */
    public function searchStudies($criteria = []) {
        // Membuat query string
        $query = '/studies?';

        if (!empty($criteria['patientName'])) {
            $query .= 'PatientName=' . urlencode($criteria['patientName']) . '&';
        }

        if (!empty($criteria['patientId'])) {
            $query .= 'PatientID=' . urlencode($criteria['patientId']) . '&';
        }

        if (!empty($criteria['studyDate'])) {
            $query .= 'StudyDate=' . urlencode($criteria['studyDate']) . '&';
        }

        if (!empty($criteria['modality'])) {
            $query .= 'Modality=' . urlencode($criteria['modality']) . '&';
        }

        $query = rtrim($query, '&');

        $result = $this->request($query);

        if ($result['success']) {
            $studies = [];
            $studyIds = $result['data'] ?? [];

            foreach ($studyIds as $studyId) {
                $studyDetails = $this->getStudyDetails($studyId);
                if ($studyDetails['success']) {
                    $studies[] = $studyDetails['data'];
                }
            }

            return ['success' => true, 'data' => $studies];
        }

        return $result;
    }

    /**
     * Get last error
     * @return string
     */
    public function getLastError() {
        return $this->lastError;
    }

    /**
     * Test koneksi
     * @return bool
     */
    public function testConnection() {
        $result = $this->request('/system');
        return $result['success'];
    }
}

?>
