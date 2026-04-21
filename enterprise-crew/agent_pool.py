"""
agent_pool.py — 企業級 Agent 工廠

所有可用角色在此定義；AgentPool.create() 根據 role key 實例化。
新增角色只需在 _SPECS 字典加一條，不需改其他檔案。
"""
from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

from crewai import Agent
from crewai_tools import SerperDevTool, FileReadTool


def _get_web_search() -> SerperDevTool:
    return SerperDevTool()


def _get_file_read() -> FileReadTool:
    return FileReadTool()


@dataclass
class _AgentSpec:
    role: str
    goal: str
    backstory: str
    tool_factories: list = field(default_factory=list)
    allow_delegation: bool = False
    max_iter: int = 15


# ─── Agent 規格表 ────────────────────────────────────────────────
_SPECS: dict[str, _AgentSpec] = {

    "system_architect": _AgentSpec(
        role="System Architect",
        goal=(
            "設計可擴展的系統架構，定義技術棧選型，輸出資料庫 schema、"
            "API 契約草稿與系統元件圖說明"
        ),
        backstory=(
            "資深全端架構師，曾主導多個千萬用戶級別系統的設計。"
            "精通微服務、DDD、CQRS、Event Sourcing 等架構模式，"
            "熟悉 AWS/GCP 基礎設施、CAP 定理與系統設計取捨。"
        ),
        tool_factories=[_get_web_search],
    ),

    "security_auditor": _AgentSpec(
        role="Security Auditor",
        goal=(
            "識別 OWASP Top 10 漏洞，執行 STRIDE 威脅模型分析，"
            "輸出安全建議報告，確保符合 GDPR / SOC2 合規要求"
        ),
        backstory=(
            "CISSP 認證資安工程師，曾在 Google、Stripe 主導安全審計。"
            "專精滲透測試、靜態程式碼分析（SAST）、"
            "依賴套件漏洞掃描（SCA）與存取控制設計。"
        ),
        tool_factories=[_get_web_search],
    ),

    "backend_developer": _AgentSpec(
        role="Backend Developer",
        goal=(
            "根據架構師的設計，實作高效能 RESTful / GraphQL API，"
            "設計資料層存取邏輯，確保單元測試覆蓋率 > 80%"
        ),
        backstory=(
            "10 年後端開發經驗，精通 Python FastAPI、Laravel、Node.js。"
            "熟悉 Redis 快取、Kafka 訊息佇列、PostgreSQL 查詢優化，"
            "以及 Clean Architecture 與 Repository Pattern。"
        ),
        tool_factories=[_get_file_read],
    ),

    "frontend_developer": _AgentSpec(
        role="Frontend Developer",
        goal=(
            "實作響應式、無障礙的使用者介面，"
            "確保 WCAG 2.1 AA 合規與 Core Web Vitals（LCP < 2.5s）達標"
        ),
        backstory=(
            "UI/UX 工程師，精通 Vue 3 Composition API、React 18、"
            "Tailwind CSS、Storybook。曾主導多個企業 Design System 建立，"
            "重視效能優化、Progressive Enhancement 與 A11y。"
        ),
        tool_factories=[_get_web_search, _get_file_read],
    ),

    "qa_engineer": _AgentSpec(
        role="QA Engineer",
        goal=(
            "設計完整的測試策略（單元 / 整合 / E2E / 效能），"
            "撰寫測試案例，識別邊界條件，輸出測試報告與覆蓋率摘要"
        ),
        backstory=(
            "測試自動化專家，熟悉 Playwright E2E、Pytest、Jest、k6 負載測試。"
            "擅長 BDD 測試設計、變異測試（Mutation Testing）"
            "與持續測試 CI 整合策略。"
        ),
        tool_factories=[_get_file_read],
    ),

    "technical_writer": _AgentSpec(
        role="Technical Writer",
        goal=(
            "撰寫清晰的 API 文件（OpenAPI 3.0）、README、架構決策紀錄（ADR），"
            "確保開發者體驗（DX）達到業界一流水準"
        ),
        backstory=(
            "前軟體工程師轉技術作家，熟悉 Diátaxis 文件框架（教程/How-to/參考/解釋）、"
            "Docusaurus、MkDocs。"
            "曾為多個開源專案撰寫被引用逾萬次的文件。"
        ),
        tool_factories=[_get_file_read],
    ),

    "devops_engineer": _AgentSpec(
        role="DevOps Engineer",
        goal=(
            "設計 CI/CD pipeline（GitHub Actions / GitLab CI）、"
            "容器化部署策略（Docker / Kubernetes）、"
            "監控告警系統（Prometheus / Grafana）"
        ),
        backstory=(
            "SRE 背景，熟悉 Kubernetes、Terraform IaC、ArgoCD GitOps、"
            "Datadog / Prometheus 可觀測性棧。"
            "目標是讓部署流程完全可重現、可回滾，MTTR < 15 分鐘。"
        ),
        tool_factories=[_get_web_search],
    ),

    "data_engineer": _AgentSpec(
        role="Data Engineer",
        goal=(
            "設計資料管道（ETL/ELT）、資料倉儲 schema（星型/雪花模型），"
            "確保資料品質、血緣追蹤與 SLA 符合"
        ),
        backstory=(
            "資料工程師，熟悉 dbt、Apache Airflow、Spark、"
            "BigQuery / Redshift OLAP 最佳化。"
            "擅長 OLAP vs OLTP 設計取捨與資料契約（Data Contract）制定。"
        ),
        tool_factories=[_get_web_search, _get_file_read],
    ),
}


# ─── 公開 API ─────────────────────────────────────────────────────
class AgentPool:
    """
    Agent 工廠。呼叫 AgentPool.create(role_key, llm) 取得 Agent 實例。

    設計原則：
    - 所有 worker agents 的 allow_delegation=False（防止無限遞迴委派）
    - tools 在呼叫時才建立（避免 import 時讀取 API key）
    - llm 由外部（Director）注入，方便替換模型
    """

    @staticmethod
    def create(role_key: str, llm: Any = None) -> Agent:
        spec = _SPECS.get(role_key)
        if spec is None:
            available = ", ".join(_SPECS.keys())
            raise ValueError(
                f"未知角色：'{role_key}'。可用角色：{available}"
            )
        kwargs: dict[str, Any] = dict(
            role=spec.role,
            goal=spec.goal,
            backstory=spec.backstory,
            tools=[factory() for factory in spec.tool_factories],
            allow_delegation=spec.allow_delegation,
            max_iter=spec.max_iter,
            verbose=True,
        )
        if llm is not None:
            kwargs["llm"] = llm
        return Agent(**kwargs)

    @staticmethod
    def available_roles() -> list[str]:
        return list(_SPECS.keys())

    @staticmethod
    def role_description(role_key: str) -> str:
        spec = _SPECS.get(role_key)
        return spec.goal if spec else "（未知角色）"
