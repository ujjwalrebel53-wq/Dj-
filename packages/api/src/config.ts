import { config as loadEnv } from "dotenv";
import { resolve } from "node:path";

loadEnv();

export const config = {
  port: Number(process.env.PORT ?? 8787),
  llmBaseUrl: process.env.LLM_BASE_URL ?? "https://api.openai.com/v1",
  llmApiKey: process.env.LLM_API_KEY ?? "",
  llmModel: process.env.LLM_MODEL ?? "gpt-4o",
  embeddingModel: process.env.EMBEDDING_MODEL ?? "text-embedding-3-small",
  dataDir: resolve(process.env.DATA_DIR ?? ".data"),
};
