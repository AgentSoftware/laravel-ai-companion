async function handler({ output, input }) {
  return {
    score: output.text === "good" ? 1 : 0,
    metadata: { prompt: input.prompt ?? null },
  };
}
