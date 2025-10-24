<?php
/**
 * Field Mapping Management API Routes
 * Manage dynamic field mappings between DevPos and QuickBooks
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

// ============================================================================
// Field Mapping Templates Routes
// ============================================================================

$app->group('/api/field-mappings', function (RouteCollectorProxy $group) {
    
    // List all mapping templates
    $group->get('/templates', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        
        $entityType = $request->getQueryParams()['entity_type'] ?? null;
        
        $sql = "SELECT 
                    t.id,
                    t.entity_type,
                    t.template_name,
                    t.description,
                    t.is_default,
                    t.is_active,
                    t.created_at,
                    t.updated_at,
                    COUNT(m.id) as mapping_count
                FROM field_mapping_templates t
                LEFT JOIN field_mappings m ON t.id = m.template_id AND m.is_active = TRUE
                WHERE 1=1";
        
        $params = [];
        if ($entityType) {
            $sql .= " AND t.entity_type = ?";
            $params[] = $entityType;
        }
        
        $sql .= " GROUP BY t.id ORDER BY t.entity_type, t.is_default DESC, t.template_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $templates = $stmt->fetchAll();
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'templates' => $templates
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    });
    
    // Get single template with all mappings
    $group->get('/templates/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $templateId = (int)$args['id'];
        
        // Get template
        $stmt = $pdo->prepare("SELECT * FROM field_mapping_templates WHERE id = ?");
        $stmt->execute([$templateId]);
        $template = $stmt->fetch();
        
        if (!$template) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Template not found'
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        // Get all mappings for this template
        $stmt = $pdo->prepare("
            SELECT * FROM field_mappings 
            WHERE template_id = ? 
            ORDER BY priority, id
        ");
        $stmt->execute([$templateId]);
        $mappings = $stmt->fetchAll();
        
        $template['mappings'] = $mappings;
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'template' => $template
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    });
    
    // Create new mapping template
    $group->post('/templates', function (Request $request, Response $response) {
        $pdo = $this->get(PDO::class);
        $data = $request->getParsedBody();
        
        $entityType = $data['entity_type'] ?? null;
        $templateName = trim($data['template_name'] ?? '');
        $description = trim($data['description'] ?? '');
        $isDefault = (bool)($data['is_default'] ?? false);
        
        if (!$entityType || !$templateName) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Entity type and template name are required'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // If setting as default, unset other defaults
        if ($isDefault) {
            $stmt = $pdo->prepare("UPDATE field_mapping_templates SET is_default = FALSE WHERE entity_type = ?");
            $stmt->execute([$entityType]);
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO field_mapping_templates (entity_type, template_name, description, is_default, created_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $userId = $_SESSION['user_id'] ?? null;
        
        try {
            $stmt->execute([$entityType, $templateName, $description, $isDefault, $userId]);
            $templateId = $pdo->lastInsertId();
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Template created successfully',
                'template_id' => $templateId
            ]));
        } catch (\PDOException $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Failed to create template: ' . $e->getMessage()
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
        
        return $response->withHeader('Content-Type', 'application/json');
    });
    
    // Update template
    $group->put('/templates/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $templateId = (int)$args['id'];
        $data = $request->getParsedBody();
        
        $updates = [];
        $params = [];
        
        if (isset($data['template_name'])) {
            $updates[] = 'template_name = ?';
            $params[] = trim($data['template_name']);
        }
        if (isset($data['description'])) {
            $updates[] = 'description = ?';
            $params[] = trim($data['description']);
        }
        if (isset($data['is_default'])) {
            $isDefault = (bool)$data['is_default'];
            if ($isDefault) {
                // Unset other defaults for this entity type
                $stmt = $pdo->prepare("
                    UPDATE field_mapping_templates 
                    SET is_default = FALSE 
                    WHERE entity_type = (SELECT entity_type FROM field_mapping_templates WHERE id = ?)
                ");
                $stmt->execute([$templateId]);
            }
            $updates[] = 'is_default = ?';
            $params[] = $isDefault;
        }
        if (isset($data['is_active'])) {
            $updates[] = 'is_active = ?';
            $params[] = (bool)$data['is_active'];
        }
        
        if (empty($updates)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'No fields to update'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $params[] = $templateId;
        
        $stmt = $pdo->prepare("
            UPDATE field_mapping_templates 
            SET " . implode(', ', $updates) . " 
            WHERE id = ?
        ");
        
        $stmt->execute($params);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Template updated successfully'
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    });
    
    // Delete template
    $group->delete('/templates/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $templateId = (int)$args['id'];
        
        // Check if template is default
        $stmt = $pdo->prepare("SELECT is_default FROM field_mapping_templates WHERE id = ?");
        $stmt->execute([$templateId]);
        $template = $stmt->fetch();
        
        if ($template && $template['is_default']) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Cannot delete default template. Set another template as default first.'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $stmt = $pdo->prepare("DELETE FROM field_mapping_templates WHERE id = ?");
        $stmt->execute([$templateId]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Template deleted successfully'
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    });
    
    // ========================================================================
    // Field Mappings Routes
    // ========================================================================
    
    // List mappings for a template
    $group->get('/templates/{templateId}/mappings', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $templateId = (int)$args['templateId'];
        
        $stmt = $pdo->prepare("
            SELECT * FROM field_mappings 
            WHERE template_id = ? 
            ORDER BY priority, id
        ");
        $stmt->execute([$templateId]);
        $mappings = $stmt->fetchAll();
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'mappings' => $mappings
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    });
    
    // Add new field mapping
    $group->post('/templates/{templateId}/mappings', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $templateId = (int)$args['templateId'];
        $data = $request->getParsedBody();
        
        $required = ['devpos_field', 'qbo_field'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => "Field '{$field}' is required"
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO field_mappings (
                template_id, devpos_field, devpos_field_type, devpos_sample_value,
                qbo_field, qbo_field_type, qbo_entity,
                transformation_type, transformation_rule, default_value,
                is_required, is_active, priority, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $templateId,
            $data['devpos_field'],
            $data['devpos_field_type'] ?? 'string',
            $data['devpos_sample_value'] ?? null,
            $data['qbo_field'],
            $data['qbo_field_type'] ?? 'string',
            $data['qbo_entity'] ?? null,
            $data['transformation_type'] ?? 'direct',
            isset($data['transformation_rule']) ? json_encode($data['transformation_rule']) : null,
            $data['default_value'] ?? null,
            (bool)($data['is_required'] ?? false),
            (bool)($data['is_active'] ?? true),
            (int)($data['priority'] ?? 100),
            $data['notes'] ?? null
        ]);
        
        $mappingId = $pdo->lastInsertId();
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Field mapping added successfully',
            'mapping_id' => $mappingId
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    });
    
    // Update field mapping
    $group->put('/mappings/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $mappingId = (int)$args['id'];
        $data = $request->getParsedBody();
        
        $updates = [];
        $params = [];
        
        $allowedFields = [
            'devpos_field', 'devpos_field_type', 'devpos_sample_value',
            'qbo_field', 'qbo_field_type', 'qbo_entity',
            'transformation_type', 'default_value',
            'is_required', 'is_active', 'priority', 'notes'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "{$field} = ?";
                
                if ($field === 'is_required' || $field === 'is_active') {
                    $params[] = (bool)$data[$field];
                } elseif ($field === 'priority') {
                    $params[] = (int)$data[$field];
                } else {
                    $params[] = $data[$field];
                }
            }
        }
        
        if (isset($data['transformation_rule'])) {
            $updates[] = 'transformation_rule = ?';
            $params[] = json_encode($data['transformation_rule']);
        }
        
        if (empty($updates)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'No fields to update'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $params[] = $mappingId;
        
        $stmt = $pdo->prepare("
            UPDATE field_mappings 
            SET " . implode(', ', $updates) . " 
            WHERE id = ?
        ");
        
        $stmt->execute($params);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Field mapping updated successfully'
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    });
    
    // Delete field mapping
    $group->delete('/mappings/{id}', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $mappingId = (int)$args['id'];
        
        $stmt = $pdo->prepare("DELETE FROM field_mappings WHERE id = ?");
        $stmt->execute([$mappingId]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Field mapping deleted successfully'
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    });
    
    // ========================================================================
    // Utility Routes
    // ========================================================================
    
    // Get available DevPos fields for an entity type
    $group->get('/devpos-fields/{entityType}', function (Request $request, Response $response, array $args) {
        $entityType = $args['entityType'];
        
        // Define available DevPos fields by entity type
        $devposFields = [
            'invoice' => [
                ['field' => 'eic', 'type' => 'string', 'description' => 'Electronic Invoice Code'],
                ['field' => 'documentNumber', 'type' => 'string', 'description' => 'Invoice number'],
                ['field' => 'issueDate', 'type' => 'date', 'description' => 'Invoice date'],
                ['field' => 'buyerName', 'type' => 'string', 'description' => 'Customer name'],
                ['field' => 'buyerNuis', 'type' => 'string', 'description' => 'Customer tax ID'],
                ['field' => 'buyerAddress', 'type' => 'string', 'description' => 'Customer address'],
                ['field' => 'buyerTown', 'type' => 'string', 'description' => 'Customer city'],
                ['field' => 'buyerCountry', 'type' => 'string', 'description' => 'Customer country'],
                ['field' => 'buyerEmail', 'type' => 'string', 'description' => 'Customer email'],
                ['field' => 'buyerPhone', 'type' => 'string', 'description' => 'Customer phone'],
                ['field' => 'totalAmount', 'type' => 'decimal', 'description' => 'Total amount'],
                ['field' => 'totalAmountWithoutVat', 'type' => 'decimal', 'description' => 'Amount before tax'],
                ['field' => 'totalAmountVat', 'type' => 'decimal', 'description' => 'Tax amount'],
                ['field' => 'vatRate', 'type' => 'decimal', 'description' => 'VAT rate %'],
                ['field' => 'currency', 'type' => 'string', 'description' => 'Currency code'],
                ['field' => 'exchangeRate', 'type' => 'decimal', 'description' => 'Exchange rate'],
                ['field' => 'dueDate', 'type' => 'date', 'description' => 'Payment due date'],
                ['field' => 'paymentTerms', 'type' => 'string', 'description' => 'Payment terms'],
                ['field' => 'notes', 'type' => 'string', 'description' => 'Invoice notes'],
                ['field' => 'internalNote', 'type' => 'string', 'description' => 'Internal note'],
                ['field' => 'items[].description', 'type' => 'string', 'description' => 'Line item description'],
                ['field' => 'items[].code', 'type' => 'string', 'description' => 'Product code'],
                ['field' => 'items[].quantity', 'type' => 'decimal', 'description' => 'Quantity'],
                ['field' => 'items[].unitPrice', 'type' => 'decimal', 'description' => 'Unit price'],
                ['field' => 'items[].amount', 'type' => 'decimal', 'description' => 'Line total'],
                ['field' => 'items[].vatRate', 'type' => 'decimal', 'description' => 'Line VAT rate'],
            ],
            'bill' => [
                ['field' => 'documentNumber', 'type' => 'string', 'description' => 'Bill number'],
                ['field' => 'issueDate', 'type' => 'date', 'description' => 'Bill date'],
                ['field' => 'sellerName', 'type' => 'string', 'description' => 'Vendor name'],
                ['field' => 'sellerNuis', 'type' => 'string', 'description' => 'Vendor tax ID'],
                ['field' => 'sellerAddress', 'type' => 'string', 'description' => 'Vendor address'],
                ['field' => 'sellerTown', 'type' => 'string', 'description' => 'Vendor city'],
                ['field' => 'sellerEmail', 'type' => 'string', 'description' => 'Vendor email'],
                ['field' => 'sellerPhone', 'type' => 'string', 'description' => 'Vendor phone'],
                ['field' => 'totalAmount', 'type' => 'decimal', 'description' => 'Total amount'],
                ['field' => 'currency', 'type' => 'string', 'description' => 'Currency code'],
                ['field' => 'dueDate', 'type' => 'date', 'description' => 'Payment due date'],
                ['field' => 'items[].description', 'type' => 'string', 'description' => 'Expense description'],
                ['field' => 'items[].amount', 'type' => 'decimal', 'description' => 'Expense amount'],
                ['field' => 'items[].category', 'type' => 'string', 'description' => 'Expense category'],
            ],
        ];
        
        $fields = $devposFields[$entityType] ?? [];
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'fields' => $fields
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    });
    
    // Get available QBO fields for an entity type
    $group->get('/qbo-fields/{entityType}', function (Request $request, Response $response, array $args) {
        $entityType = $args['entityType'];
        
        // Define available QBO fields by entity type
        $qboFields = [
            'invoice' => [
                ['field' => 'DocNumber', 'type' => 'string', 'description' => 'Invoice number'],
                ['field' => 'TxnDate', 'type' => 'date', 'description' => 'Transaction date'],
                ['field' => 'DueDate', 'type' => 'date', 'description' => 'Due date'],
                ['field' => 'CustomerRef.value', 'type' => 'reference', 'entity' => 'Customer', 'description' => 'Customer ID'],
                ['field' => 'CustomerMemo.value', 'type' => 'string', 'description' => 'Customer memo'],
                ['field' => 'PrivateNote', 'type' => 'string', 'description' => 'Private note'],
                ['field' => 'CurrencyRef.value', 'type' => 'reference', 'entity' => 'Currency', 'description' => 'Currency'],
                ['field' => 'ExchangeRate', 'type' => 'decimal', 'description' => 'Exchange rate'],
                ['field' => 'Line[].Amount', 'type' => 'decimal', 'description' => 'Line amount'],
                ['field' => 'Line[].Description', 'type' => 'string', 'description' => 'Line description'],
                ['field' => 'Line[].SalesItemLineDetail.ItemRef.value', 'type' => 'reference', 'entity' => 'Item', 'description' => 'Item ID'],
                ['field' => 'Line[].SalesItemLineDetail.Qty', 'type' => 'decimal', 'description' => 'Quantity'],
                ['field' => 'Line[].SalesItemLineDetail.UnitPrice', 'type' => 'decimal', 'description' => 'Unit price'],
                ['field' => 'TxnTaxDetail.TotalTax', 'type' => 'decimal', 'description' => 'Total tax'],
                ['field' => 'CustomField[0].StringValue', 'type' => 'string', 'description' => 'Custom field'],
            ],
            'bill' => [
                ['field' => 'DocNumber', 'type' => 'string', 'description' => 'Bill number'],
                ['field' => 'TxnDate', 'type' => 'date', 'description' => 'Transaction date'],
                ['field' => 'DueDate', 'type' => 'date', 'description' => 'Due date'],
                ['field' => 'VendorRef.value', 'type' => 'reference', 'entity' => 'Vendor', 'description' => 'Vendor ID'],
                ['field' => 'PrivateNote', 'type' => 'string', 'description' => 'Private note'],
                ['field' => 'CurrencyRef.value', 'type' => 'reference', 'entity' => 'Currency', 'description' => 'Currency'],
                ['field' => 'Line[].Amount', 'type' => 'decimal', 'description' => 'Line amount'],
                ['field' => 'Line[].Description', 'type' => 'string', 'description' => 'Line description'],
                ['field' => 'Line[].AccountBasedExpenseLineDetail.AccountRef.value', 'type' => 'reference', 'entity' => 'Account', 'description' => 'Expense account'],
            ],
        ];
        
        $fields = $qboFields[$entityType] ?? [];
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'fields' => $fields
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    });
    
    // Clone existing template
    $group->post('/templates/{id}/clone', function (Request $request, Response $response, array $args) {
        $pdo = $this->get(PDO::class);
        $templateId = (int)$args['id'];
        $data = $request->getParsedBody();
        
        $newName = trim($data['new_name'] ?? '');
        if (empty($newName)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'New template name is required'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Get original template
        $stmt = $pdo->prepare("SELECT * FROM field_mapping_templates WHERE id = ?");
        $stmt->execute([$templateId]);
        $template = $stmt->fetch();
        
        if (!$template) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Template not found'
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        // Create new template
        $stmt = $pdo->prepare("
            INSERT INTO field_mapping_templates (entity_type, template_name, description, is_default, created_by)
            VALUES (?, ?, ?, FALSE, ?)
        ");
        $stmt->execute([
            $template['entity_type'],
            $newName,
            $data['description'] ?? "Cloned from: {$template['template_name']}",
            $_SESSION['user_id'] ?? null
        ]);
        
        $newTemplateId = $pdo->lastInsertId();
        
        // Clone all mappings
        $stmt = $pdo->prepare("
            INSERT INTO field_mappings (
                template_id, devpos_field, devpos_field_type, devpos_sample_value,
                qbo_field, qbo_field_type, qbo_entity,
                transformation_type, transformation_rule, default_value,
                is_required, is_active, priority, notes
            )
            SELECT 
                ?, devpos_field, devpos_field_type, devpos_sample_value,
                qbo_field, qbo_field_type, qbo_entity,
                transformation_type, transformation_rule, default_value,
                is_required, is_active, priority, notes
            FROM field_mappings
            WHERE template_id = ?
        ");
        $stmt->execute([$newTemplateId, $templateId]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Template cloned successfully',
            'new_template_id' => $newTemplateId
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    });
    
})->add($container->get('AuthMiddleware'));
