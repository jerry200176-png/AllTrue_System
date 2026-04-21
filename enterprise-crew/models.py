"""
models.py — 所有 Pydantic 資料模型

Director 的需求分析結果輸出為 ProjectSpec；
各 Agent 的產出透過 TaskOutput 傳遞。
"""
from __future__ import annotations

from typing import Literal
from pydantic import BaseModel, Field


# ─── 專案規模與類型 ────────────────────────────────────────────────
ProjectType = Literal[
    "web_app",
    "api_service",
    "data_pipeline",
    "cli_tool",
    "library",
    "mobile_app",
]

Complexity = Literal["small", "medium", "large", "enterprise"]

# 所有合法的 Agent 角色鍵（與 AgentPool 的 key 對齊）
RoleKey = Literal[
    "system_architect",
    "security_auditor",
    "backend_developer",
    "frontend_developer",
    "qa_engineer",
    "technical_writer",
    "devops_engineer",
    "data_engineer",
]


# ─── 需求分析產出（Director → 動態組隊依據）────────────────────────
class ProjectSpec(BaseModel):
    """Director Agent 分析使用者需求後產出的結構化規格"""

    project_name: str = Field(description="從需求推斷出的專案名稱（英文）")
    project_type: ProjectType = Field(description="專案類型")
    complexity: Complexity = Field(
        description=(
            "專案複雜度：small=單一功能 <1 週, medium=多模組 1–4 週, "
            "large=跨服務 >1 月, enterprise=組織級系統"
        )
    )
    required_roles: list[RoleKey] = Field(
        description=(
            "必要角色列表，從以下選取：system_architect, backend_developer, "
            "frontend_developer, qa_engineer, technical_writer, security_auditor, "
            "devops_engineer, data_engineer。依職責只選必要角色，不得全選。"
        )
    )
    requirements_breakdown: list[str] = Field(
        description="將使用者需求拆解成 3–8 條可執行的子需求"
    )
    tech_stack_hints: list[str] = Field(
        default=[],
        description="使用者提到或推薦的技術（語言、框架、資料庫）",
    )
    security_critical: bool = Field(
        description="是否涉及敏感資料、金融、醫療或高權限操作"
    )
    needs_ci_cd: bool = Field(description="是否需要 CI/CD pipeline 設計")
    execution_phases: list[str] = Field(
        description=(
            "依序執行的工作階段，例如 ['architecture', 'backend', 'frontend', "
            "'security', 'qa', 'docs', 'devops']。只列入本專案實際需要的階段。"
        )
    )


# ─── 各 Agent 交付物（供 Manager 彙整）────────────────────────────
class PhaseOutput(BaseModel):
    """單一工作階段的產出摘要"""

    phase: str
    agent_role: str
    deliverable_summary: str = Field(description="本階段的主要產出摘要（200 字內）")
    key_decisions: list[str] = Field(default=[], description="重要決策或結論")
    risks: list[str] = Field(default=[], description="識別出的風險或待確認事項")


class FinalReport(BaseModel):
    """Manager 最終彙整報告"""

    project_name: str
    complexity: Complexity
    executive_summary: str = Field(description="給非技術決策者的 3–5 句摘要")
    phase_outputs: list[PhaseOutput]
    tech_stack: list[str]
    next_steps: list[str] = Field(description="建議的下一步行動（含優先順序）")
    open_questions: list[str] = Field(
        default=[], description="尚待澄清或決策的問題"
    )
