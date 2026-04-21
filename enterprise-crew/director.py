"""
director.py — 企業級 AI 開發總監

職責：
1. analyze()   — 接收使用者需求，呼叫 LLM 輸出結構化 ProjectSpec
2. assemble()  — 根據 ProjectSpec 從 AgentPool 選取 agents
3. run()       — 建立 Crew 並執行，回傳最終報告

分層架構：
  使用者需求
      ↓
  Director.analyze()  ← 單 Agent Crew，輸出 ProjectSpec（Pydantic）
      ↓
  Director.assemble() ← AgentPool 按需實例化
      ↓
  Director.run()      ← Sequential Crew 執行（線性階段 + 彙整）
      ↓
  FinalReport（Pydantic）
"""
from __future__ import annotations

import json
from typing import Any

from crewai import Agent, Crew, LLM, Process, Task
from rich.console import Console
from rich.panel import Panel
from rich.table import Table

from agent_pool import AgentPool
from models import FinalReport, ProjectSpec
from task_builder import TaskBuilder

console = Console()


class Director:
    """
    AI 開發總監：動態分析需求、組建最適團隊、協調執行、彙整產出。
    """

    def __init__(
        self,
        model: str = "gpt-4o",
        temperature: float = 0.2,
        verbose: bool = True,
    ) -> None:
        self.llm = LLM(model=f"openai/{model}", temperature=temperature)
        self.verbose = verbose

    # ─────────────────────────────────────────────────────────────
    # Phase 1：需求分析 → ProjectSpec
    # ─────────────────────────────────────────────────────────────

    def analyze(self, user_requirement: str) -> ProjectSpec:
        """
        用單一 Agent + 單一 Task 的迷你 Crew 分析需求，
        輸出結構化 ProjectSpec（Pydantic 確保型別安全）。
        """
        console.print(
            Panel(
                "[bold cyan]Phase 1：需求分析中...[/bold cyan]",
                expand=False,
            )
        )

        analyst = Agent(
            role="Requirements Analyst",
            goal=(
                "分析使用者的業務需求，拆解成結構化的專案規格，"
                "判斷合適的 agent 角色與執行階段順序"
            ),
            backstory=(
                "資深產品架構師，曾在頂尖科技公司主導百人研發團隊的需求分析。"
                "擅長從模糊的業務描述中識別技術風險、確定最小可行範圍、"
                "並動態調配最合適的技術人才。"
            ),
            llm=self.llm,
            verbose=self.verbose,
            allow_delegation=False,
        )

        available_roles = ", ".join(AgentPool.available_roles())

        analysis_task = Task(
            description=(
                f"分析以下業務需求，輸出完整的 ProjectSpec：\n\n"
                f"---\n{user_requirement}\n---\n\n"
                f"可選的 agent 角色（required_roles 只能從以下選取）：\n"
                f"{available_roles}\n\n"
                "判斷標準：\n"
                "- small：< 1 週，單一功能，1–3 個角色\n"
                "- medium：1–4 週，多模組，3–5 個角色\n"
                "- large：> 1 月，跨服務，4–6 個角色\n"
                "- enterprise：組織級系統，5–8 個角色\n\n"
                "execution_phases 的合法值：\n"
                "architecture, backend, frontend, security, qa, docs, devops, data\n\n"
                "安全關鍵判斷：涉及金融、醫療、個資、高權限操作時設為 true。"
            ),
            expected_output=(
                "一個完整的 ProjectSpec JSON 物件，"
                "包含 project_name, project_type, complexity, "
                "required_roles, requirements_breakdown, tech_stack_hints, "
                "security_critical, needs_ci_cd, execution_phases。"
                "required_roles 必須是 execution_phases 中階段對應角色的子集。"
            ),
            output_pydantic=ProjectSpec,
            agent=analyst,
        )

        crew = Crew(
            agents=[analyst],
            tasks=[analysis_task],
            process=Process.sequential,
            verbose=self.verbose,
        )
        crew.kickoff()

        spec: ProjectSpec = analysis_task.output.pydantic  # type: ignore[union-attr]
        self._print_spec_summary(spec)
        return spec

    # ─────────────────────────────────────────────────────────────
    # Phase 2：根據 ProjectSpec 組建動態團隊
    # ─────────────────────────────────────────────────────────────

    def assemble(self, spec: ProjectSpec) -> dict[str, Agent]:
        """
        根據 spec.required_roles 從 AgentPool 實例化 agents。
        只建立本次專案真正需要的角色（避免無謂消耗）。
        """
        console.print(
            Panel(
                "[bold cyan]Phase 2：組建動態團隊...[/bold cyan]",
                expand=False,
            )
        )

        agents: dict[str, Agent] = {}
        table = Table(title="本次組隊名單", show_lines=True)
        table.add_column("角色 Key", style="cyan")
        table.add_column("職稱", style="green")
        table.add_column("核心目標", style="white", max_width=50)

        for role_key in spec.required_roles:
            agent = AgentPool.create(role_key, llm=self.llm)
            agents[role_key] = agent
            table.add_row(
                role_key,
                agent.role,
                AgentPool.role_description(role_key)[:50] + "...",
            )

        console.print(table)
        return agents

    # ─────────────────────────────────────────────────────────────
    # Phase 3：Sequential Crew 執行（線性階段 + 彙整）
    # ─────────────────────────────────────────────────────────────

    def run(
        self,
        spec: ProjectSpec,
        agents: dict[str, Agent],
    ) -> FinalReport:
        """
        建立 Task 清單，以 sequential process 線性執行各階段，
        最後由 summary_agent 讀取所有 context 彙整報告。
        """
        console.print(
            Panel(
                "[bold cyan]Phase 3：啟動 Sequential Crew...[/bold cyan]",
                expand=False,
            )
        )

        tasks = TaskBuilder.build(spec, agents)
        if not tasks:
            raise RuntimeError(
                "無法為此專案規格建立任何 Task，"
                "請檢查 required_roles 與 execution_phases 對應關係。"
            )

        summary_agent = Agent(
            role="Project Director",
            goal=(
                "彙整所有團隊成員的產出，整合成一份完整、無衝突的最終交付報告，"
                "並提出清晰的下一步行動建議"
            ),
            backstory=(
                "CTO 級別的技術總監，擅長從多個專業領域的產出中提煉核心決策，"
                "化解技術方向的歧異，輸出高品質的執行路線圖。"
            ),
            llm=self.llm,
            verbose=self.verbose,
            allow_delegation=False,
        )

        summary_task = Task(
            description=(
                f"彙整「{spec.project_name}」所有工作階段的產出，輸出最終交付報告。\n\n"
                "報告必須包含：\n"
                "1. 給非技術決策者的執行摘要（3–5 句）\n"
                "2. 每個工作階段的產出摘要（100 字內）\n"
                "3. 最終確認的技術棧\n"
                "4. 按優先順序排列的下一步行動清單\n"
                "5. 尚待澄清或需決策的開放問題\n\n"
                f"專案複雜度：{spec.complexity}"
            ),
            expected_output=(
                "一份完整的 FinalReport，含 executive_summary、"
                "各階段 PhaseOutput 清單、tech_stack、next_steps、open_questions。"
            ),
            output_pydantic=FinalReport,
            agent=summary_agent,
            context=tasks,  # 讀取所有前置 task 的輸出
        )

        all_agents = list(agents.values()) + [summary_agent]
        all_tasks = tasks + [summary_task]

        crew = Crew(
            agents=all_agents,
            tasks=all_tasks,
            process=Process.sequential,
            verbose=self.verbose,
        )

        crew.kickoff()

        report: FinalReport = summary_task.output.pydantic  # type: ignore[union-attr]
        self._print_final_report(report)
        return report

    # ─────────────────────────────────────────────────────────────
    # 主入口：一次性完成分析 → 組隊 → 執行
    # ─────────────────────────────────────────────────────────────

    def kickoff(self, user_requirement: str) -> FinalReport:
        """
        完整執行三個階段，回傳最終報告。

        使用範例：
            director = Director()
            report = director.kickoff("我需要一個電商平台後台管理系統...")
        """
        console.print(
            Panel(
                f"[bold white]🚀 企業級 AI 開發總監啟動[/bold white]\n"
                f"[dim]{user_requirement[:100]}{'...' if len(user_requirement) > 100 else ''}[/dim]",
                title="AllTrue Enterprise CrewAI",
                expand=False,
            )
        )

        spec = self.analyze(user_requirement)
        agents = self.assemble(spec)
        report = self.run(spec, agents)
        return report

    # ─────────────────────────────────────────────────────────────
    # Rich 輸出輔助方法
    # ─────────────────────────────────────────────────────────────

    def _print_spec_summary(self, spec: ProjectSpec) -> None:
        table = Table(title="需求分析結果", show_lines=True)
        table.add_column("項目", style="cyan", width=18)
        table.add_column("內容", style="white")

        table.add_row("專案名稱", spec.project_name)
        table.add_row("類型", spec.project_type)
        table.add_row("複雜度", spec.complexity)
        table.add_row("必要角色", "\n".join(spec.required_roles))
        table.add_row("執行階段", " → ".join(spec.execution_phases))
        table.add_row("安全關鍵", "✅ 是" if spec.security_critical else "❌ 否")
        table.add_row("需要 CI/CD", "✅ 是" if spec.needs_ci_cd else "❌ 否")
        table.add_row(
            "子需求",
            "\n".join(f"• {r}" for r in spec.requirements_breakdown),
        )

        console.print(table)

    def _print_final_report(self, report: FinalReport) -> None:
        console.print(
            Panel(
                f"[bold green]{report.executive_summary}[/bold green]",
                title=f"📋 {report.project_name} — 執行摘要",
                expand=False,
            )
        )

        table = Table(title="各階段產出摘要", show_lines=True)
        table.add_column("階段", style="cyan", width=16)
        table.add_column("負責角色", style="yellow", width=20)
        table.add_column("產出摘要", style="white")

        for phase_out in report.phase_outputs:
            table.add_row(
                phase_out.phase,
                phase_out.agent_role,
                phase_out.deliverable_summary[:80] + "...",
            )

        console.print(table)

        if report.next_steps:
            console.print("\n[bold]📌 下一步行動：[/bold]")
            for i, step in enumerate(report.next_steps, 1):
                console.print(f"  {i}. {step}")

        if report.open_questions:
            console.print("\n[bold yellow]❓ 開放問題：[/bold yellow]")
            for q in report.open_questions:
                console.print(f"  • {q}")
