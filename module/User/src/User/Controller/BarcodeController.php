<?php
namespace User\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use Zend\Db\Adapter\AdapterAwareTrait;

class BarcodeController extends AbstractActionController
{
    use AdapterAwareTrait;

    // GET /user/account/barcode-lookup?barcode=...
    public function lookupAction()
    {
        $barcode = $this->params()->fromQuery('barcode');
        error_log('DEBUG: Received barcode param: [' . $barcode . ']');
        if (!$barcode) {
            error_log('DEBUG: No barcode provided');
            return new JsonModel(['error' => 'No barcode provided']);
        }
        $adapter = $this->adapter;
        $sql = 'SELECT drink_id FROM drink_barcodes WHERE barcode = ?';
        error_log('DEBUG: SQL: ' . $sql . ' PARAM: [' . $barcode . ']');
        $result = $adapter->query($sql, [$barcode])->toArray();
        error_log('DEBUG: Query result: ' . print_r($result, true));
        if ($result && isset($result[0]['drink_id'])) {
            error_log('DEBUG: Found drink_id: ' . $result[0]['drink_id']);
            return new JsonModel(['drink_id' => (int)$result[0]['drink_id']]);
        }
        error_log('DEBUG: Barcode not found in DB');
        return new JsonModel(['drink_id' => null]);
    }

    // POST /user/account/barcode-assign { barcode, drink_id }
    public function assignAction()
    {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(['success' => false, 'error' => 'Not POST']);
        }
        $data = json_decode($request->getContent(), true);
        $barcode = isset($data['barcode']) ? trim($data['barcode']) : null;
        $drinkId = isset($data['drink_id']) ? (int)$data['drink_id'] : null;
        if (!$barcode || !$drinkId) {
            return new JsonModel(['success' => false, 'error' => 'Missing data']);
        }
        $adapter = $this->adapter;
        // Remove any previous mapping for this barcode
        $adapter->query('DELETE FROM drink_barcodes WHERE barcode = ?', [$barcode]);
        // Insert new mapping
        $adapter->query('INSERT INTO drink_barcodes (barcode, drink_id) VALUES (?, ?)', [$barcode, $drinkId]);
        return new JsonModel(['success' => true]);
    }
}
