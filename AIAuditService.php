<?php
/**
 * AI审核服务 - 基于 Provider 架构，支持多平台评论内容审核
 *
 * @package CommentAI
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class CommentAI_AIAuditService
{
    private $config;
    private $threshold;

    public function __construct($config)
    {
        $this->config = $config;
        $this->threshold = floatval($config->auditThreshold ?: 0.8);
    }

    /**
     * 构建审核专用的 Provider 配置（复用回复配置作为兜底）
     */
    private function buildAuditConfig()
    {
        $auditConfig = clone $this->config;
        $auditConfig->aiProvider = $this->config->auditProvider ?: $this->config->aiProvider;
        $auditConfig->apiKey = $this->config->auditApiKey ?: $this->config->apiKey;
        $auditConfig->apiEndpoint = !empty($this->config->auditApiEndpoint)
            ? $this->config->auditApiEndpoint
            : $this->config->apiEndpoint;
        $auditConfig->modelName = $this->config->auditModelName ?: $this->config->modelName;
        $auditConfig->temperature = '0.1';
        $auditConfig->maxTokens = '200';
        return $auditConfig;
    }

    /**
     * 审核评论内容
     *
     * @param string $commentText 评论内容
     * @return array ['passed' => bool, 'confidence' => float, 'reason' => string]
     */
    public function auditComment($commentText)
    {
        $messages = $this->buildAuditMessages($commentText);

        require_once __DIR__ . '/AIService.php';
        $provider = CommentAI_AIService::create($this->buildAuditConfig());

        $response = $provider->sendMessages($messages);

        return $this->parseAuditResponse($response);
    }

    /**
     * 构建审核消息
     */
    private function buildAuditMessages($commentText)
    {
        $messages = array();

        // 系统提示词 - 审核专用
        $systemPrompt = "你是一个内容审核助手，负责判断评论是否符合社区规范。\n\n"
                     . "审核标准：\n"
                     . "1. 不包含政治敏感内容\n"
                     . "2. 不包含暴力、恐怖内容\n"
                     . "3. 不包含色情、低俗内容\n"
                     . "4. 不包含赌博、违法内容\n"
                     . "5. 不包含人身攻击、侮辱性内容\n"
                     . "6. 不包含垃圾广告、诈骗信息\n\n"
                     . "请对以下评论进行审核，并返回：\n"
                     . "- 审核结果（通过/不通过）\n"
                     . "- 置信度（0-1之间）\n"
                     . "- 审核理由\n\n"
                     . "输出格式：\n"
                     . "{\"passed\": true/false, \"confidence\": 0.9, \"reason\": \"审核理由\"}";

        $messages[] = array(
            'role' => 'system',
            'content' => $systemPrompt
        );

        // 用户消息 - 待审核的评论
        $messages[] = array(
            'role' => 'user',
            'content' => "待审核评论：\n{$commentText}"
        );

        return $messages;
    }

    /**
     * 解析审核响应
     */
    private function parseAuditResponse($aiResponse)
    {
        $aiResponse = is_string($aiResponse) ? trim($aiResponse) : '';
        if ($aiResponse === '') {
            throw new Exception('无法从响应中提取审核结果');
        }

        // 尝试从AI回复中提取JSON格式的审核结果
        $match = array();
        if (preg_match('/\{[^}]*"passed"[^}]*\}/', $aiResponse, $match)) {
            $auditResult = json_decode($match[0], true);
            if (isset($auditResult['passed']) && isset($auditResult['confidence'])) {
                return array(
                    'passed' => (bool)$auditResult['passed'],
                    'confidence' => floatval($auditResult['confidence']),
                    'reason' => isset($auditResult['reason']) && !empty($auditResult['reason']) ? $auditResult['reason'] : ($auditResult['passed'] ? '审核通过' : '审核未通过')
                );
            }
        }

        // 如果AI没有返回JSON格式，基于内容判断
        $lowerResponse = strtolower($aiResponse);
        if (strpos($lowerResponse, '通过') !== false || strpos($lowerResponse, 'approved') !== false) {
            return array(
                'passed' => true,
                'confidence' => 0.9,
                'reason' => '审核通过'
            );
        } else {
            $reason = !empty($aiResponse) ? '审核未通过: ' . $aiResponse : '审核未通过: 内容不符合规范';
            return array(
                'passed' => false,
                'confidence' => 0.9,
                'reason' => $reason
            );
        }
    }

    /**
     * 测试审核服务连接
     *
     * @return array ['success' => bool, 'message' => string, 'result' => array]
     */
    public function testConnection()
    {
        try {
            $testComment = '这是一条测试评论，内容正常，没有敏感信息。';
            $result = $this->auditComment($testComment);

            return array(
                'success' => true,
                'message' => 'AI审核服务连接成功！',
                'result' => $result
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'AI审核服务连接失败: ' . $e->getMessage(),
                'result' => array()
            );
        }
    }
}
